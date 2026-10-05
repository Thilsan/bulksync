<?php

namespace App\Http\Controllers;

use App\Jobs\PushBarcodeImagesJob;
use App\Jobs\RunBarcodeImageDownloadJob;
use App\Models\BarcodeImageSession;
use App\Models\Store;
use App\Services\ProductImageScraper;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Barcode Image Grabber: a list of internal barcodes and a website, and back
 * comes a ZIP with one folder per barcode holding that product's pictures.
 */
class BarcodeImageController extends Controller
{
    /**
     * Every action is behind the same permission, so the check sits here rather
     * than at the top of nine methods. The sidebar already hides the link; this
     * is what stops somebody who has the URL.
     */
    /** More sites than this is a list nobody reads the results of. */
    private const MAX_SITES = 5;

    public function __construct(private readonly ProductImageScraper $scraper)
    {
        abort_unless(auth()->user()?->hasFeature('barcode_images'), 403);
    }

    public function index()
    {
        $mine = BarcodeImageSession::where('user_id', auth()->id());

        $totals = (clone $mine)->where('status', 'completed')
            ->selectRaw('COUNT(*) AS runs')
            ->selectRaw('COALESCE(SUM(total_barcodes), 0) AS barcodes')
            ->selectRaw('COALESCE(SUM(found_count), 0) AS found')
            ->selectRaw('COALESCE(SUM(images_downloaded), 0) AS images')
            ->first();

        $recent = (clone $mine)->latest()->take(8)->get();

        return view('barcode-images.index', compact('totals', 'recent'));
    }

    /**
     * Every run, newest first — everyone's, for a super admin.
     *
     * The grab reads somebody else's website from this workspace's server, so
     * who ran what is worth being able to answer. A super admin gets the whole
     * list with the name beside each row; everyone else sees their own, where
     * a name column would say the same thing on every line.
     *
     * The form screen stays personal either way: it shows what you have been
     * doing lately next to the box you start the next run from.
     */
    public function history()
    {
        $user = auth()->user();

        $sessions = BarcodeImageSession::query()
            ->unless($user->is_super_admin, fn ($query) => $query->where('user_id', $user->id))
            ->with('user')
            ->latest()
            ->paginate(20);

        return view('barcode-images.history', compact('sessions'));
    }

    public function start(Request $request)
    {
        $request->validate([
            'site_url' => ['required', 'string', 'max:1000'],
            'barcodes' => ['nullable', 'string'],
            'csv_file' => ['nullable', 'file', 'mimes:csv,txt', 'max:20480'],
            'name'     => ['nullable', 'string', 'max:120'],
        ]);

        $sites = $this->parseSites($request->string('site_url')->toString());

        if ($sites === []) {
            return back()
                ->withInput()
                ->withErrors(['site_url' => 'That does not look like a website address. Try something like bluesalon.com.']);
        }

        $barcodes = $this->parseBarcodes($request);

        if ($barcodes === []) {
            return back()
                ->withInput()
                ->withErrors(['barcodes' => 'Please enter at least one barcode or upload a CSV.']);
        }

        $session = BarcodeImageSession::create([
            'user_id'        => auth()->id(),
            'name'           => $request->string('name')->toString() ?: null,
            'site_url'       => $sites[0],
            'site_urls'      => $sites,
            'status'         => 'pending',
            'total_barcodes' => count($barcodes),
            'raw_barcodes'   => implode("\n", $barcodes),
        ]);

        RunBarcodeImageDownloadJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('barcode-images.show', $session);
    }

    public function show(BarcodeImageSession $barcodeImageSession)
    {
        $this->authorise($barcodeImageSession);

        return view('barcode-images.show', [
            'session' => $barcodeImageSession->load('user', 'pushStore'),
            'stores'  => Store::accessibleBy(auth()->user())->orderBy('name')->get(),
        ]);
    }

    public function status(BarcodeImageSession $barcodeImageSession)
    {
        $this->authorise($barcodeImageSession);

        return response()->json([
            'status'    => $barcodeImageSession->status,
            'total'     => $barcodeImageSession->total_barcodes,
            'processed' => $barcodeImageSession->processed,
            'progress'  => $barcodeImageSession->progressPercent(),
            'found'     => $barcodeImageSession->found_count,
            'missing'   => $barcodeImageSession->missing_count,
            'blocked'   => $barcodeImageSession->blocked_count,
            'badge'     => $barcodeImageSession->statusBadge(),
            'images'    => $barcodeImageSession->images_downloaded,
            'error'     => $barcodeImageSession->error_message,

            'push' => [
                'status'   => $barcodeImageSession->push_status,
                'total'    => $barcodeImageSession->push_total,
                'done'     => $barcodeImageSession->push_done,
                'pushed'   => $barcodeImageSession->push_pushed,
                'failed'   => $barcodeImageSession->push_failed,
                'progress' => $barcodeImageSession->pushProgressPercent(),
                'store'    => $barcodeImageSession->pushStore?->name,
                'error'    => $barcodeImageSession->push_error,
            ],
        ]);
    }

    public function items(BarcodeImageSession $barcodeImageSession, Request $request)
    {
        $this->authorise($barcodeImageSession);

        $filter = $request->get('filter', 'all');
        $search = trim((string) $request->get('q', ''));

        $query = $barcodeImageSession->items();

        if ($filter === 'found') {
            $query->where('status', 'found');
        } elseif ($filter === 'missing') {
            $query->whereIn('status', ['not_found', 'failed', 'blocked']);
        }

        if ($search !== '') {
            $query->where('barcode', 'like', "%{$search}%");
        }

        $items = $query->orderBy('id')->paginate(50)->withQueryString();

        return response()->json([
            'items'     => $items->items(),
            'total'     => $items->total(),
            'page'      => $items->currentPage(),
            'last_page' => $items->lastPage(),
        ]);
    }

    /**
     * Send this run's pictures to a Shopify store.
     *
     * The website is asked for rather than assumed: the images were grabbed
     * from one site to be put on another, so the active store is as likely to
     * be wrong as right, and getting it wrong writes to a live catalogue.
     */
    public function push(BarcodeImageSession $barcodeImageSession, Request $request)
    {
        $this->authorise($barcodeImageSession);

        $data = $request->validate([
            'store_id'      => ['required', 'integer'],
            'matching_mode' => ['required', 'in:sku_barcode,style_code'],
        ]);

        // Through the user's own stores, so an id typed into the form cannot
        // reach a store they have no access to.
        $store = Store::accessibleBy(auth()->user())->findOrFail($data['store_id']);

        if ($barcodeImageSession->status !== 'completed') {
            return back()->withErrors(['store_id' => 'Wait for the grab to finish before pushing it.']);
        }

        if ($barcodeImageSession->images_downloaded === 0) {
            return back()->withErrors(['store_id' => 'This run has no images to push.']);
        }

        // Re-pushing is allowed — a first attempt can miss on a store that was
        // not ready — but not while one is already going, which would have two
        // jobs uploading the same pictures to the same products.
        if ($barcodeImageSession->isPushing()) {
            return back()->withErrors(['store_id' => 'A push is already running for this grab.']);
        }

        $barcodeImageSession->update([
            'push_status'        => 'pending',
            'push_store_id'      => $store->id,
            'push_matching_mode' => $data['matching_mode'],
            'push_total'         => 0,
            'push_done'          => 0,
            'push_pushed'        => 0,
            'push_failed'        => 0,
            'push_error'         => null,
        ]);

        PushBarcodeImagesJob::dispatch($barcodeImageSession->id)->onQueue('bulkupload');

        return back()->with('success', "Pushing to {$store->name}. It continues in the background.");
    }

    /**
     * The whole run as a ZIP, one folder per barcode.
     *
     * Built on demand rather than kept: a second copy of every image on disk is
     * the surest way to fill it, and rebuilding costs only the time to read
     * files that are already local.
     */
    public function download(BarcodeImageSession $barcodeImageSession): BinaryFileResponse
    {
        $this->authorise($barcodeImageSession);

        $root = $barcodeImageSession->directory();

        abort_unless(is_dir($root), 404, 'This run has no images on disk.');

        $zipPath = tempnam(sys_get_temp_dir(), 'barcode-images-') . '.zip';
        $zip     = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create the download.');
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($entries as $entry) {
            if (!$entry->isFile()) continue;

            // Relative to the run's own folder, so the ZIP opens as
            // <barcode>/<barcode>-1.jpg and nothing above it leaks in.
            $zip->addFile($entry->getPathname(), ltrim(str_replace($root, '', $entry->getPathname()), '/'));
        }

        $count = $zip->numFiles;
        $zip->close();

        if ($count === 0) {
            @unlink($zipPath);
            abort(404, 'Nothing was downloaded for this run.');
        }

        $stem = Str::slug($barcodeImageSession->name ?: 'barcode-images') ?: 'barcode-images';

        return response()->download($zipPath, "{$stem}-{$barcodeImageSession->id}.zip")->deleteFileAfterSend(true);
    }

    /** One barcode's folder on its own, for when only that product is wanted. */
    public function downloadOne(BarcodeImageSession $barcodeImageSession, string $barcode): BinaryFileResponse
    {
        $this->authorise($barcodeImageSession);

        $folder = $barcodeImageSession->folderFor($barcode);

        abort_unless(is_dir($folder), 404, 'No images were saved for that barcode.');

        $zipPath = tempnam(sys_get_temp_dir(), 'barcode-image-') . '.zip';
        $zip     = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create the download.');
        }

        $name = BarcodeImageSession::safeFolder($barcode);

        foreach (glob("{$folder}/*") ?: [] as $file) {
            if (is_file($file)) {
                $zip->addFile($file, $name . '/' . basename($file));
            }
        }

        $count = $zip->numFiles;
        $zip->close();

        if ($count === 0) {
            @unlink($zipPath);
            abort(404, 'No images were saved for that barcode.');
        }

        return response()->download($zipPath, "{$name}.zip")->deleteFileAfterSend(true);
    }

    public function destroy(BarcodeImageSession $barcodeImageSession)
    {
        $this->authorise($barcodeImageSession);

        // Files first: a deleted row leaves nothing that knows the folder is
        // there, and the sweep in routes/console.php is the only thing that
        // would ever find it again.
        $barcodeImageSession->deleteFiles();
        $barcodeImageSession->delete();

        return redirect()->route('barcode-images.index')->with('success', 'Run deleted.');
    }

    /**
     * A run is readable by whoever started it, and by a super admin.
     *
     * Deleting is covered by the same rule: a super admin is who gets asked to
     * clear out a run that filled the disk, and the files are the point of it.
     */
    private function authorise(BarcodeImageSession $session): void
    {
        $user = auth()->user();

        abort_if($session->user_id !== $user->id && !$user->is_super_admin, 403);
    }

    /** @return string[] */
    /**
     * The websites a run was given, in the order they were typed.
     *
     * One per line, or separated by commas — nobody knows which catalogue
     * carries a given article, so a run takes several and keeps the first that
     * answers with pictures. Anything that is not a website is dropped rather
     * than refusing the whole list, and duplicates are collapsed so a site is
     * never asked the same question twice.
     *
     * @return list<string>
     */
    private function parseSites(string $input): array
    {
        $sites = [];

        foreach (preg_split('/[\r\n,;]+/', $input) ?: [] as $candidate) {
            if ($site = $this->scraper->normaliseSite($candidate)) {
                $sites[$site] = true;
            }
        }

        return array_slice(array_keys($sites), 0, self::MAX_SITES);
    }

    private function parseBarcodes(Request $request): array
    {
        $raw = [];

        if ($request->hasFile('csv_file')) {
            $handle = fopen($request->file('csv_file')->getRealPath(), 'r');

            $first = true;

            while (($row = fgetcsv($handle)) !== false) {
                $value = trim((string) ($row[0] ?? ''));

                // A header row is a word, not a barcode. Skipped only if the
                // very first cell has no digits in it at all.
                if ($first) {
                    $first = false;

                    if ($value === '' || !preg_match('/\d/', $value)) continue;
                }

                if ($value !== '') $raw[] = $value;
            }

            fclose($handle);
        }

        foreach (preg_split('/[\r\n,;\t]+/', (string) $request->input('barcodes')) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') $raw[] = $line;
        }

        return array_values(array_unique($raw));
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\RunBarcodeImageDownloadJob;
use App\Models\BarcodeImageSession;
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

    public function history()
    {
        $sessions = BarcodeImageSession::where('user_id', auth()->id())
            ->latest()
            ->paginate(20);

        return view('barcode-images.history', compact('sessions'));
    }

    public function start(Request $request)
    {
        $request->validate([
            'site_url' => ['required', 'string', 'max:255'],
            'barcodes' => ['nullable', 'string'],
            'csv_file' => ['nullable', 'file', 'mimes:csv,txt', 'max:20480'],
            'name'     => ['nullable', 'string', 'max:120'],
        ]);

        $site = $this->scraper->normaliseSite($request->string('site_url')->toString());

        if (!$site) {
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
            'site_url'       => $site,
            'status'         => 'pending',
            'total_barcodes' => count($barcodes),
            'raw_barcodes'   => implode("\n", $barcodes),
        ]);

        RunBarcodeImageDownloadJob::dispatch($session->id)->onQueue('bulkupload');

        return redirect()->route('barcode-images.show', $session);
    }

    public function show(BarcodeImageSession $barcodeImageSession)
    {
        abort_if($barcodeImageSession->user_id !== auth()->id(), 403);

        return view('barcode-images.show', ['session' => $barcodeImageSession]);
    }

    public function status(BarcodeImageSession $barcodeImageSession)
    {
        abort_if($barcodeImageSession->user_id !== auth()->id(), 403);

        return response()->json([
            'status'    => $barcodeImageSession->status,
            'total'     => $barcodeImageSession->total_barcodes,
            'processed' => $barcodeImageSession->processed,
            'progress'  => $barcodeImageSession->progressPercent(),
            'found'     => $barcodeImageSession->found_count,
            'missing'   => $barcodeImageSession->missing_count,
            'images'    => $barcodeImageSession->images_downloaded,
            'error'     => $barcodeImageSession->error_message,
        ]);
    }

    public function items(BarcodeImageSession $barcodeImageSession, Request $request)
    {
        abort_if($barcodeImageSession->user_id !== auth()->id(), 403);

        $filter = $request->get('filter', 'all');
        $search = trim((string) $request->get('q', ''));

        $query = $barcodeImageSession->items();

        if ($filter === 'found') {
            $query->where('status', 'found');
        } elseif ($filter === 'missing') {
            $query->whereIn('status', ['not_found', 'failed']);
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
     * The whole run as a ZIP, one folder per barcode.
     *
     * Built on demand rather than kept: a second copy of every image on disk is
     * the surest way to fill it, and rebuilding costs only the time to read
     * files that are already local.
     */
    public function download(BarcodeImageSession $barcodeImageSession): BinaryFileResponse
    {
        abort_if($barcodeImageSession->user_id !== auth()->id(), 403);

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
        abort_if($barcodeImageSession->user_id !== auth()->id(), 403);

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
        abort_if($barcodeImageSession->user_id !== auth()->id(), 403);

        // Files first: a deleted row leaves nothing that knows the folder is
        // there, and the sweep in routes/console.php is the only thing that
        // would ever find it again.
        $barcodeImageSession->deleteFiles();
        $barcodeImageSession->delete();

        return redirect()->route('barcode-images.index')->with('success', 'Run deleted.');
    }

    /** @return string[] */
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

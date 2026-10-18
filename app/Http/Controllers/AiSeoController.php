<?php

namespace App\Http\Controllers;

use App\Services\AiSeoException;
use App\Services\AiSeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI SEO Support: the screens in front of the easybits SEO engine.
 *
 * Quick Wins is the landing page, a ranked list of the pages with the most
 * upside. Each row opens the Page Analyzer, which audits one page and offers
 * AI rewrites; each finding there can be marked resolved.
 *
 * The analyzer page loads empty and fetches its report in the background,
 * because the engine takes up to 25 seconds and a page that blank-screens for
 * that long looks broken.
 */
class AiSeoController extends Controller
{
    public function __construct(private AiSeoService $seo)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeFeature($request);

        $properties = $this->seo->properties();
        $property   = $this->property($request);

        $result = null;
        $error  = null;

        try {
            $result = $this->seo->quickWins($property);
        } catch (AiSeoException $e) {
            $error = $e->getMessage();
        }

        return view('ai-seo.index', [
            'properties' => $properties,
            'property'   => $property,
            'summary'    => $result['summary'] ?? null,
            'quickWins'  => array_values(array_filter((array) ($result['quickWins'] ?? []), 'is_array')),
            'error'      => $error,
        ]);
    }

    /** The analyzer's frame. The report itself arrives from report(). */
    public function analyze(Request $request)
    {
        $this->authorizeFeature($request);

        $property = $this->property($request);
        $url      = trim((string) $request->query('url', ''));

        return view('ai-seo.analyze', [
            'properties' => $this->seo->properties(),
            'property'   => $property,
            'url'        => $url,
            'urlError'   => $url !== '' ? $this->urlProblem($url, $property) : null,
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        $this->authorizeFeature($request);

        $data = $request->validate([
            'url'      => ['required', 'string', 'max:2048'],
            'property' => ['required', 'string'],
        ]);

        if ($problem = $this->urlProblem($data['url'], $data['property'])) {
            return response()->json(['message' => $problem], 422);
        }

        try {
            return response()->json($this->seo->analyze($data['url'], $data['property']));
        } catch (AiSeoException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function resolve(Request $request): JsonResponse
    {
        $this->authorizeFeature($request);

        $data = $request->validate([
            'findingId' => ['required', 'string', 'max:255'],
            'url'       => ['required', 'string', 'max:2048'],
            'property'  => ['required', 'string'],
            'issue'     => ['required', 'string', 'max:1000'],
            'category'  => ['required', 'string', 'max:255'],
        ]);

        if ($problem = $this->urlProblem($data['url'], $data['property'])) {
            return response()->json(['message' => $problem], 422);
        }

        try {
            return response()->json($this->seo->markResolved($data));
        } catch (AiSeoException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    private function authorizeFeature(Request $request): void
    {
        abort_unless($request->user()?->hasFeature('ai_seo'), 403, 'You do not have access to AI SEO Support.');
    }

    /** The store asked for, or the first one when it is missing or unknown. */
    private function property(Request $request): string
    {
        $asked = (string) $request->input('property', '');

        return $this->seo->isKnownProperty($asked)
            ? $asked
            : (string) array_key_first($this->seo->properties());
    }

    /**
     * Why a URL cannot be analysed, or null when it can.
     *
     * Kept to the store's own domain: the engine fetches whatever it is given,
     * and it is only ever meant to be pointed at our sites.
     */
    private function urlProblem(string $url, string $property): ?string
    {
        if (!$this->seo->isKnownProperty($property)) {
            return 'That store is not connected to AI SEO Support.';
        }

        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return 'Enter a full page address, starting with https://';
        }

        if (!$this->seo->urlBelongsTo($url, $property)) {
            return 'That page is not on ' . $this->seo->domainOf($property) . '. Pick the matching store first.';
        }

        return null;
    }
}

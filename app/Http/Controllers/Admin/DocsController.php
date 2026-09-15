<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DocsService;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Admin → Guides: the docs/ files, live from the code.
 */
class DocsController extends Controller
{
    public function __construct(private readonly DocsService $docs) {}

    public function index(): View
    {
        return view('admin.docs.index', ['docs' => $this->docs->all()]);
    }

    public function show(string $doc): View
    {
        $page = $this->docs->page($doc);
        abort_if($page === null, 404);

        return view('admin.docs.show', [
            'page' => $page,
            'others' => collect($this->docs->all())->where('slug', '!=', $doc)->values(),
        ]);
    }

    /** The original Markdown file. */
    public function download(string $doc): Response
    {
        $page = $this->docs->page($doc);
        abort_if($page === null, 404);

        return response((string) file_get_contents(base_path($page['file'])), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.basename($page['file']).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

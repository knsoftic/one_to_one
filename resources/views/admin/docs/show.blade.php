<x-layouts.admin :title="$page['title']" :heading="$page['title']" :subheading="$page['file'].' · '.$page['minutes'].' min read · updated '.$page['updated_at']->format('j M Y, H:i')" :back="route('admin.docs')">
    <div class="doc-layout" data-doc>
        <aside class="doc-aside">
            <details class="card doc-toc" open data-doc-toc>
                <summary class="doc-toc-title"><x-icon name="list-tree" /> On this page</summary>
                <nav aria-label="Table of contents">
                    <ol>
                        @foreach ($page['toc'] as $item)
                            <li class="doc-toc-level-{{ $item['level'] }}"><a href="#{{ $item['id'] }}" data-doc-link="{{ $item['id'] }}">{{ $item['text'] }}</a></li>
                        @endforeach
                    </ol>
                </nav>
                <div class="doc-toc-actions">
                    <a href="{{ route('admin.docs.download', $page['slug']) }}" class="btn btn-ghost btn-sm"><x-icon name="download" /> Download .md</a>
                </div>
            </details>
            @if ($others->isNotEmpty())
                <div class="card doc-others">
                    <span class="doc-toc-title"><x-icon name="book-open" /> Other guides</span>
                    @foreach ($others as $other)
                        <a href="{{ route('admin.docs.show', $other['slug']) }}"><x-icon :name="$other['icon']" /> {{ $other['title'] }}</a>
                    @endforeach
                </div>
            @endif
        </aside>

        <article class="card doc-article">
            <div class="card-body doc-prose">
                {!! $page['html'] !!}
            </div>
        </article>
    </div>
</x-layouts.admin>

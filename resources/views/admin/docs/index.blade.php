<x-layouts.admin title="Guides" heading="Guides" subheading="The step-by-step guides from the code (docs/ folder), always the version that is deployed.">
    <div class="doc-index">
        @forelse ($docs as $doc)
            <article class="card doc-card">
                <div class="card-body">
                    <a href="{{ route('admin.docs.show', $doc['slug']) }}" class="doc-card-head">
                        <span class="doc-card-icon"><x-icon :name="$doc['icon']" /></span>
                        <span>
                            <strong class="doc-card-title">{{ $doc['title'] }}</strong>
                            <small class="doc-card-meta">{{ count($doc['sections']) }} sections · {{ $doc['minutes'] }} min read · updated {{ $doc['updated_at']->diffForHumans() }}</small>
                        </span>
                    </a>
                    <p class="doc-card-text">{{ $doc['text'] }}</p>
                    @if ($doc['sections'])
                        <ol class="doc-card-sections">
                            @foreach ($doc['sections'] as $section)
                                <li><a href="{{ route('admin.docs.show', $doc['slug']) }}#{{ $section['id'] }}">{{ $section['text'] }}</a></li>
                            @endforeach
                        </ol>
                    @endif
                    <div class="doc-card-actions">
                        <a href="{{ route('admin.docs.show', $doc['slug']) }}" class="btn btn-primary btn-sm"><x-icon name="book-open" /> Open guide</a>
                        <a href="{{ route('admin.docs.download', $doc['slug']) }}" class="btn btn-ghost btn-sm"><x-icon name="download" /> Markdown</a>
                    </div>
                </div>
            </article>
        @empty
            <div class="card"><div class="card-body admin-muted">No guides were found in the docs/ folder.</div></div>
        @endforelse
    </div>
</x-layouts.admin>

@php $editing = $campaign->exists; @endphp
<x-layouts.admin :title="$editing ? $campaign->name : 'New ad'" :heading="$editing ? $campaign->name : 'New ad'" subheading="A sponsored card shown in the chat list." :back="route('admin.ads')">
    <form method="POST" action="{{ $editing ? route('admin.ads.update', $campaign) : route('admin.ads.store') }}" enctype="multipart/form-data" class="admin-ad-editor" data-ad-editor data-loading-form novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="admin-ad-layout">
            <div class="admin-stack">
                {{-- What people see --}}
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="badge-dollar-sign" /> The ad</h3>
                        <div class="admin-form">
                            <div class="form-group">
                                <label for="ad-name" class="form-label">Campaign name <span class="optional">(only you see this)</span></label>
                                <input id="ad-name" name="name" maxlength="120" required class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $campaign->name) }}" placeholder="Eid sale — March">
                                @error('name')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            </div>
                            <div class="form-group">
                                <label for="ad-title" class="form-label">Headline</label>
                                <input id="ad-title" name="title" maxlength="80" required class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $campaign->title) }}" placeholder="Up to 50% off this Eid" data-ad-field="title">
                                @error('title')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            </div>
                            <div class="form-group">
                                <label for="ad-body" class="form-label">Text <span class="optional">(optional)</span></label>
                                <textarea id="ad-body" name="body" rows="2" maxlength="200" class="form-control @error('body') is-invalid @enderror" placeholder="Free delivery on orders over Rs 2000." data-ad-field="body">{{ old('body', $campaign->body) }}</textarea>
                                @error('body')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            </div>
                            <div class="business-grid">
                                <div class="form-group">
                                    <label for="ad-sponsor" class="form-label">Sponsor name <span class="optional">(optional)</span></label>
                                    <input id="ad-sponsor" name="sponsor" maxlength="60" class="form-control" value="{{ old('sponsor', $campaign->sponsor) }}" placeholder="Your shop" data-ad-field="sponsor">
                                </div>
                                <div class="form-group">
                                    <label for="ad-cta" class="form-label">Button text</label>
                                    <input id="ad-cta" name="cta_label" maxlength="24" required class="form-control @error('cta_label') is-invalid @enderror" value="{{ old('cta_label', $campaign->cta_label ?: 'Learn more') }}" data-ad-field="cta">
                                    @error('cta_label')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="ad-url" class="form-label">Link (where the button goes)</label>
                                <input id="ad-url" type="url" name="target_url" maxlength="600" required class="form-control @error('target_url') is-invalid @enderror" value="{{ old('target_url', $campaign->target_url) }}" placeholder="https://your-shop.com/eid">
                                @error('target_url')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            </div>
                            <div class="form-group">
                                <label for="ad-image" class="form-label">Image <span class="optional">(optional, wide picture works best)</span></label>
                                <input id="ad-image" type="file" name="image" accept="image/png,image/jpeg,image/webp" class="form-control @error('image') is-invalid @enderror" data-ad-image>
                                @error('image')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                @if ($campaign->imageUrl())
                                    <label class="checkbox mt-2"><input type="checkbox" name="remove_image" value="1" data-ad-remove-image> Remove the current image</label>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Who sees it --}}
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="target" /> Who sees this ad</h3>
                        <p class="admin-muted">Leave a target empty to reach everyone. Interests, age and gender only reach people who turned <strong>Personalised ads</strong> on.</p>
                        <div class="admin-form">
                            <div class="form-group">
                                <span class="form-label">Countries</span>
                                <details class="admin-disclosure admin-ad-countries">
                                    @php($chosen = old('countries', $campaign->countries ?? []))
                                    <summary class="btn btn-secondary btn-sm"><x-icon name="map-pinned" /> {{ $chosen ? count($chosen).' selected' : 'All countries' }} <x-icon name="chevron-down" class="admin-disclosure-chevron" /></summary>
                                    <div class="admin-ad-country-list">
                                        @foreach ($countries as $code => $name)
                                            <label class="checkbox"><input type="checkbox" name="countries[]" value="{{ $code }}" @checked(in_array($code, $chosen, true))> {{ $name }}</label>
                                        @endforeach
                                    </div>
                                </details>
                            </div>
                            <div class="form-group">
                                <span class="form-label">Audience segments <span class="optional">(personalised)</span></span>
                                <div class="admin-chips is-wrap">
                                    @php($segs = old('interests', $campaign->interests ?? []))
                                    @foreach (\App\Models\AdCampaign::SEGMENTS as $key => $label)
                                        <label class="admin-chip is-check"><input type="checkbox" name="interests[]" value="{{ $key }}" @checked(in_array($key, $segs, true))><span>{{ $label }}</span></label>
                                    @endforeach
                                </div>
                            </div>
                            <div class="business-grid">
                                <div class="form-group">
                                    <label for="ad-min-age" class="form-label">Min age <span class="optional">(personalised)</span></label>
                                    <input id="ad-min-age" type="number" name="min_age" min="13" max="100" class="form-control @error('min_age') is-invalid @enderror" value="{{ old('min_age', $campaign->min_age) }}">
                                    @error('min_age')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="ad-max-age" class="form-label">Max age <span class="optional">(personalised)</span></label>
                                    <input id="ad-max-age" type="number" name="max_age" min="13" max="100" class="form-control @error('max_age') is-invalid @enderror" value="{{ old('max_age', $campaign->max_age) }}">
                                    @error('max_age')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="ad-gender" class="form-label">Gender <span class="optional">(personalised)</span></label>
                                    <select id="ad-gender" name="gender" class="form-control">
                                        <option value="">Everyone</option>
                                        @foreach (\App\Models\AdCampaign::GENDERS as $key => $label)
                                            <option value="{{ $key }}" @selected(old('gender', $campaign->gender) === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <label class="checkbox">
                                <input type="hidden" name="personalised_only" value="0">
                                <input type="checkbox" name="personalised_only" value="1" @checked(old('personalised_only', $campaign->personalised_only))>
                                Only show to people who turned personalised ads on
                            </label>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Preview, schedule, actions --}}
            <div class="admin-stack">
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="badge-info" /> Preview</h3>
                        <div class="ad-card admin-ad-preview" data-ad-preview>
                            <div class="ad-card-media" data-ad-preview-media @if ($campaign->imageUrl()) style="background-image:url('{{ $campaign->imageUrl() }}')" @endif></div>
                            <div class="ad-card-body">
                                <span class="ad-card-tag" data-ad-preview-tag>Sponsored{{ $campaign->sponsor ? ' · '.$campaign->sponsor : '' }}</span>
                                <span class="ad-card-title" data-ad-preview-title>{{ $campaign->title ?: 'Your headline' }}</span>
                                <span class="ad-card-text" data-ad-preview-body>{{ $campaign->body }}</span>
                                <span class="ad-card-cta" data-ad-preview-cta>{{ $campaign->cta_label ?: 'Learn more' }} <x-icon name="square-arrow-out-up-right" /></span>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="clock" /> Status &amp; schedule</h3>
                        <div class="admin-form">
                            <div class="form-group">
                                <label for="ad-status" class="form-label">Status</label>
                                <select id="ad-status" name="status" class="form-control">
                                    @foreach (\App\Models\AdCampaign::STATUSES as $value)
                                        <option value="{{ $value }}" @selected(old('status', $campaign->status) === $value)>{{ ['draft' => 'Draft (not shown)', 'active' => 'Live', 'paused' => 'Paused'][$value] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="business-grid">
                                <div class="form-group">
                                    <label for="ad-starts" class="form-label">Start <span class="optional">(optional)</span></label>
                                    <input id="ad-starts" type="datetime-local" name="starts_at" class="form-control" value="{{ old('starts_at', $campaign->starts_at?->format('Y-m-d\TH:i')) }}">
                                </div>
                                <div class="form-group">
                                    <label for="ad-ends" class="form-label">End <span class="optional">(optional)</span></label>
                                    <input id="ad-ends" type="datetime-local" name="ends_at" class="form-control @error('ends_at') is-invalid @enderror" value="{{ old('ends_at', $campaign->ends_at?->format('Y-m-d\TH:i')) }}">
                                    @error('ends_at')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                </div>
                            </div>
                            <div class="business-grid">
                                <div class="form-group">
                                    <label for="ad-cap" class="form-label">Max per person / day</label>
                                    <input id="ad-cap" type="number" name="per_user_daily_cap" min="1" max="50" required class="form-control" value="{{ old('per_user_daily_cap', $campaign->per_user_daily_cap ?: 3) }}">
                                </div>
                                <div class="form-group">
                                    <label for="ad-weight" class="form-label">Priority (1–100)</label>
                                    <input id="ad-weight" type="number" name="weight" min="1" max="100" required class="form-control" value="{{ old('weight', $campaign->weight ?: 1) }}">
                                    <p class="form-hint">Higher shows more often than other live ads.</p>
                                </div>
                            </div>
                        </div>
                        <div class="admin-ad-actions">
                            <button type="submit" class="btn btn-primary"><x-icon name="check" /> {{ $editing ? 'Save ad' : 'Create ad' }}</button>
                            <a href="{{ route('admin.ads') }}" class="btn btn-ghost">Cancel</a>
                        </div>
                    </div>
                </section>

                @if ($editing)
                    <section class="card">
                        <div class="card-body">
                            <div class="admin-card-head-inline">
                                <h3 class="admin-section-title"><x-icon name="chart-no-axes-column" /> Performance</h3>
                                <span class="admin-muted">{{ number_format($campaign->impressions) }} views · {{ number_format($campaign->clicks) }} clicks · {{ $campaign->ctr() }}% CTR</span>
                            </div>
                            @if (!empty($days))
                                <x-admin.bars :series="$days" label="Views per day" />
                            @else
                                <p class="admin-muted">No views yet.</p>
                            @endif
                        </div>
                    </section>

                    <form method="POST" action="{{ route('admin.ads.destroy', $campaign) }}" data-confirm="The campaign &quot;{{ $campaign->name }}&quot; and its stats will be deleted." data-confirm-title="Delete this ad?" data-confirm-label="Delete" data-confirm-danger>
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-ghost is-danger"><x-icon name="trash-2" /> Delete campaign</button>
                    </form>
                @endif
            </div>
        </div>
    </form>
</x-layouts.admin>

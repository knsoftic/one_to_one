{{-- Business tools (X8): business profile, away and greeting messages, quick replies, labels --}}
@php
    $business = $user->businessProfile()->first();
    $quickReplies = $user->quickReplies()->orderBy('shortcut')->get();
    $profileErrors = $errors->getBag('business');
    $messageErrors = $errors->getBag('businessMessages');
    $replyErrors = $errors->getBag('quickReply');
    $local = fn ($date) => $date?->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i');
@endphp

@unless ($business)
    <div class="wa-group business-intro">
        <div class="business-intro-art"><x-icon name="briefcase-business" /></div>
        <h3 class="business-intro-title">Use {{ config('app.name') }} for your business</h3>
        <ul class="business-intro-list">
            <li><x-icon name="store" /> A business profile with what you do, your address, hours, email and website</li>
            <li><x-icon name="clock-8" /> Away messages when you're closed, and a greeting for new customers</li>
            <li><x-icon name="zap" /> Quick replies: type <kbd>/</kbd> in a chat to send a saved answer</li>
            <li><x-icon name="tags" /> Labels: colour your chat lists (Orders, Paid, New customer…)</li>
        </ul>
        <p class="wa-group-note">Fill in your business profile below to turn it on. You can go back to a normal account at any time.</p>
    </div>
@endunless

{{-- Business profile --}}
<form method="POST" action="{{ route('business.profile') }}" class="wa-group business-form" data-loading-form novalidate>
    @csrf
    @method('PUT')
    <h3 class="wa-group-title">Business profile</h3>
    <div class="wa-form">
        <div class="form-group">
            <label class="form-label" for="business-category">Category</label>
            <select id="business-category" name="category" class="form-control @if ($profileErrors->has('category')) is-invalid @endif" required>
                <option value="">Choose a category</option>
                @foreach (\App\Models\BusinessProfile::CATEGORIES as $value => $label)
                    <option value="{{ $value }}" @selected(old('category', $business?->category) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if ($profileErrors->has('category'))<p class="form-error"><x-icon name="circle-alert" />{{ $profileErrors->first('category') }}</p>@endif
        </div>
        <div class="form-group">
            <label class="form-label" for="business-description">Description <span class="optional">(optional)</span></label>
            <textarea id="business-description" name="description" rows="3" maxlength="512" class="form-control" placeholder="What you sell or do, in a sentence or two">{{ old('description', $business?->description) }}</textarea>
        </div>
        <div class="business-grid">
            <div class="form-group">
                <label class="form-label" for="business-address">Address <span class="optional">(optional)</span></label>
                <input id="business-address" name="address" maxlength="255" class="form-control" value="{{ old('address', $business?->address) }}" placeholder="Shop 12, Liberty Market, Lahore">
            </div>
            <div class="form-group">
                <label class="form-label" for="business-email">Business email <span class="optional">(optional)</span></label>
                <input id="business-email" type="email" name="email" maxlength="191" class="form-control @if ($profileErrors->has('email')) is-invalid @endif" value="{{ old('email', $business?->email) }}">
                @if ($profileErrors->has('email'))<p class="form-error"><x-icon name="circle-alert" />{{ $profileErrors->first('email') }}</p>@endif
            </div>
            <div class="form-group">
                <label class="form-label" for="business-website">Website <span class="optional">(optional)</span></label>
                <input id="business-website" type="url" name="website" maxlength="255" class="form-control @if ($profileErrors->has('website')) is-invalid @endif" value="{{ old('website', $business?->website) }}" placeholder="https://">
                @if ($profileErrors->has('website'))<p class="form-error"><x-icon name="circle-alert" />{{ $profileErrors->first('website') }}</p>@endif
            </div>
        </div>

        <fieldset class="business-hours" data-business-hours>
            <legend class="form-label">Business hours</legend>
            <div class="tone-segments business-hours-modes">
                @foreach (\App\Models\BusinessProfile::HOURS_MODES as $value => $label)
                    <label class="tone-segment"><input type="radio" name="hours_mode" value="{{ $value }}" @checked(old('hours_mode', $business?->hoursMode() ?? 'custom') === $value) data-hours-mode><span>{{ $label }}</span></label>
                @endforeach
            </div>
            <div class="business-days" data-hours-days>
                @foreach (\App\Models\BusinessProfile::DAYS as $key => $label)
                    @php
                        $day = $business?->day($key) ?? ['open' => $key !== 'sun', 'from' => '09:00', 'to' => '18:00'];
                    @endphp
                    <div class="business-day">
                        <label class="checkbox business-day-name">
                            <input type="hidden" name="days[{{ $key }}][open]" value="0">
                            <input type="checkbox" name="days[{{ $key }}][open]" value="1" @checked(old("days.$key.open", $day['open'])) data-day-open>
                            <span>{{ $label }}</span>
                        </label>
                        <span class="business-day-times">
                            <input type="time" name="days[{{ $key }}][from]" value="{{ old("days.$key.from", $day['from']) }}" class="form-control" aria-label="{{ $label }} opens">
                            <span aria-hidden="true">–</span>
                            <input type="time" name="days[{{ $key }}][to]" value="{{ old("days.$key.to", $day['to']) }}" class="form-control" aria-label="{{ $label }} closes">
                        </span>
                    </div>
                @endforeach
            </div>
        </fieldset>
        <div class="wa-form-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="check" /> {{ $business ? 'Save business profile' : 'Turn on business account' }}</button>
        </div>
    </div>
</form>

@if ($business)
    {{-- Away and greeting messages --}}
    <form method="POST" action="{{ route('business.messages') }}" class="wa-group business-form" data-loading-form novalidate>
        @csrf
        @method('PUT')
        <h3 class="wa-group-title">Automatic messages</h3>
        <div class="wa-form">
            <label class="business-switch">
                <span><strong>Away message</strong><small>Sent automatically when someone writes while you're away (at most once a day per chat).</small></span>
                <span class="switch"><input type="hidden" name="away_enabled" value="0"><input type="checkbox" name="away_enabled" value="1" @checked(old('away_enabled', $business->away_enabled))><span class="switch-track"></span></span>
            </label>
            <div class="form-group">
                <textarea name="away_message" rows="2" maxlength="1000" class="form-control @if ($messageErrors->has('away_message')) is-invalid @endif" placeholder="Thanks for your message! We're closed right now and will reply when we're back.">{{ old('away_message', $business->away_message) }}</textarea>
                @if ($messageErrors->has('away_message'))<p class="form-error"><x-icon name="circle-alert" />{{ $messageErrors->first('away_message') }}</p>@endif
            </div>
            <div class="business-grid">
                <div class="form-group">
                    <label class="form-label" for="away-schedule">When</label>
                    <select id="away-schedule" name="away_schedule" class="form-control @if ($messageErrors->has('away_schedule')) is-invalid @endif" data-away-schedule>
                        @foreach (\App\Models\BusinessProfile::AWAY_SCHEDULES as $value => $label)
                            <option value="{{ $value }}" @selected(old('away_schedule', $business->away_schedule) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($messageErrors->has('away_schedule'))<p class="form-error"><x-icon name="circle-alert" />{{ $messageErrors->first('away_schedule') }}</p>@endif
                </div>
                <div class="form-group">
                    <label class="form-label" for="away-recipients">Send to</label>
                    <select id="away-recipients" name="away_recipients" class="form-control">
                        @foreach (\App\Models\BusinessProfile::RECIPIENTS as $value => $label)
                            <option value="{{ $value }}" @selected(old('away_recipients', $business->away_recipients) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="business-grid" data-away-custom>
                <div class="form-group">
                    <label class="form-label" for="away-from">From</label>
                    <input id="away-from" type="datetime-local" name="away_from" value="{{ old('away_from', $local($business->away_from)) }}" class="form-control @if ($messageErrors->has('away_from')) is-invalid @endif">
                </div>
                <div class="form-group">
                    <label class="form-label" for="away-until">Until</label>
                    <input id="away-until" type="datetime-local" name="away_until" value="{{ old('away_until', $local($business->away_until)) }}" class="form-control @if ($messageErrors->has('away_until')) is-invalid @endif">
                    @if ($messageErrors->has('away_until'))<p class="form-error"><x-icon name="circle-alert" />{{ $messageErrors->first('away_until') }}</p>@endif
                </div>
            </div>

            <label class="business-switch">
                <span><strong>Greeting message</strong><small>Welcomes people who write for the first time, or after {{ \App\Models\BusinessProfile::GREETING_AFTER_DAYS }} days without messages.</small></span>
                <span class="switch"><input type="hidden" name="greeting_enabled" value="0"><input type="checkbox" name="greeting_enabled" value="1" @checked(old('greeting_enabled', $business->greeting_enabled))><span class="switch-track"></span></span>
            </label>
            <div class="form-group">
                <textarea name="greeting_message" rows="2" maxlength="1000" class="form-control @if ($messageErrors->has('greeting_message')) is-invalid @endif" placeholder="Hi! Thanks for contacting us. How can we help?">{{ old('greeting_message', $business->greeting_message) }}</textarea>
                @if ($messageErrors->has('greeting_message'))<p class="form-error"><x-icon name="circle-alert" />{{ $messageErrors->first('greeting_message') }}</p>@endif
            </div>
            <div class="form-group">
                <label class="form-label" for="greeting-recipients">Send to</label>
                <select id="greeting-recipients" name="greeting_recipients" class="form-control">
                    @foreach (\App\Models\BusinessProfile::RECIPIENTS as $value => $label)
                        <option value="{{ $value }}" @selected(old('greeting_recipients', $business->greeting_recipients) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="wa-form-actions"><button type="submit" class="btn btn-primary"><x-icon name="check" /> Save automatic messages</button></div>
        </div>
    </form>

    {{-- Quick replies --}}
    <div class="wa-group">
        <h3 class="wa-group-title">Quick replies</h3>
        <p class="wa-group-note is-top">In any chat, type <kbd>/</kbd> and the shortcut to send a saved answer.</p>
        <div class="quick-reply-list">
            @forelse ($quickReplies as $reply)
                <details class="quick-reply">
                    <summary>
                        <span class="quick-reply-shortcut">/{{ $reply->shortcut }}</span>
                        <span class="quick-reply-text">{{ $reply->message }}</span>
                        <x-icon name="pencil" class="quick-reply-edit" />
                    </summary>
                    <form method="POST" action="{{ route('quick-replies.update', $reply) }}" class="wa-form quick-reply-form">
                        @csrf
                        @method('PUT')
                        <input name="shortcut" maxlength="24" class="form-control" value="{{ $reply->shortcut }}" aria-label="Shortcut" required>
                        <textarea name="message" rows="3" maxlength="1000" class="form-control" aria-label="Message" required>{{ $reply->message }}</textarea>
                        <div class="wa-form-actions">
                            <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            <button type="submit" form="delete-quick-reply-{{ $reply->id }}" class="btn btn-ghost btn-sm is-danger">Delete</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('quick-replies.destroy', $reply) }}" id="delete-quick-reply-{{ $reply->id }}" data-confirm="The quick reply /{{ $reply->shortcut }} will be deleted." data-confirm-title="Delete quick reply?" data-confirm-label="Delete" data-confirm-danger>
                        @csrf
                        @method('DELETE')
                    </form>
                </details>
            @empty
                <p class="wa-group-note is-top">No quick replies yet.</p>
            @endforelse
        </div>
        <form method="POST" action="{{ route('quick-replies.store') }}" class="wa-form quick-reply-new" data-loading-form novalidate>
            @csrf
            <div class="input-wrap quick-reply-shortcut-input">
                <span class="quick-reply-slash" aria-hidden="true">/</span>
                <input name="shortcut" maxlength="24" class="form-control @if ($replyErrors->has('shortcut')) is-invalid @endif" value="{{ old('shortcut') }}" placeholder="thanks" aria-label="Shortcut" required>
            </div>
            @if ($replyErrors->has('shortcut'))<p class="form-error"><x-icon name="circle-alert" />{{ $replyErrors->first('shortcut') }}</p>@endif
            <textarea name="message" rows="2" maxlength="1000" class="form-control @if ($replyErrors->has('message')) is-invalid @endif" placeholder="Thank you for your order! We'll deliver it today." aria-label="Message" required>{{ old('message') }}</textarea>
            @if ($replyErrors->has('message'))<p class="form-error"><x-icon name="circle-alert" />{{ $replyErrors->first('message') }}</p>@endif
            <div class="wa-form-actions"><button type="submit" class="btn btn-secondary"><x-icon name="plus" /> Add quick reply</button></div>
        </form>
    </div>

    <div class="wa-group">
        <h3 class="wa-group-title">Labels</h3>
        <a href="{{ route('chat.index') }}" class="wa-row is-link">
            <x-icon name="tags" class="wa-row-icon" />
            <span class="wa-row-body">
                <span class="wa-row-title">Label your chats</span>
                <span class="wa-row-text">Make a chat list with a colour (e.g. "New order", "Paid"), then use "Add to list" on a chat. Coloured lists show as labels on the chats.</span>
            </span>
            <x-icon name="chevron-right" class="wa-row-chevron" />
        </a>
    </div>

    <form method="POST" action="{{ route('business.destroy') }}" class="wa-group" data-confirm="Your business profile and automatic messages will be removed. Quick replies and lists stay." data-confirm-title="Turn off business account?" data-confirm-label="Turn off" data-confirm-danger>
        @csrf
        @method('DELETE')
        <button type="submit" class="wa-row is-danger"><x-icon name="briefcase-business" class="wa-row-icon" /><span class="wa-row-body"><span class="wa-row-title">Turn off business account</span></span></button>
    </form>
@endif

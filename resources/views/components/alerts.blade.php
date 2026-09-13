@if (session('status'))
    <div class="alert alert-success" role="status">
        <x-icon name="circle-check" />
        <span>{{ session('status') }}</span>
    </div>
@endif

@if (session('error'))
    <div class="alert alert-error" role="alert">
        <x-icon name="circle-alert" />
        <span>{{ session('error') }}</span>
    </div>
@endif

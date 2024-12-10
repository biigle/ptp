    <sidebar-tab name="ptp" icon="circle" title="Perform Point to Polygon conversion">
        <div id="ptp-container"></div>
    </sidebar-tab>
@push('scripts')
    <script src="{{ cachebust_asset('vendor/ptp/scripts/main.js') }}"></script>
@endpush

@push("styles")
<link href="{{ cachebust_asset('vendor/ptp/styles/main.css') }}" rel="stylesheet">
<link href="{{ cachebust_asset('vendor/largo/styles/main.css') }}" rel="stylesheet">

@endpush


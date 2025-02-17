@if (($user->can('edit-in', $volume) || $user->can('sudo')) && $volume->isImageVolume() && !$volume->hasTiledImages())
    <sidebar-tab name="ptp" icon="hat-wizard" title="Perform Point to Polygon conversion">
        <div id="ptp-container"></div>
    </sidebar-tab>
@endif
@push('scripts')
    <script src="{{ cachebust_asset('vendor/ptp/scripts/main.js') }}"></script>
    <script type="text/javascript">
        biigle.$declare('volumes.isRunning', {{ isset($volume->attrs['ptp_job_id']) }} );
    </script>
@endpush


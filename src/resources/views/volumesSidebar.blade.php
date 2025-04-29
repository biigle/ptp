@if ($user->can('edit-in', $volume) && $volume->isImageVolume() && !$volume->hasTiledImages())
    <sidebar-tab name="ptp" icon="hat-wizard" title="Perform Point to Polygon conversion">
        <div id="ptp-container"></div>
    </sidebar-tab>
@endif
@push('scripts')
    {{vite_hot(base_path('vendor/ptp/hot'), ['src/resources/assets/js/main.js'], 'vendor/ptp')}}
    <script type="module">
        biigle.$declare('volumes.isRunning', {{ isset($volume->attrs['ptp_job_id']) ? 'true' : 'false' }} );
    </script>
@endpush


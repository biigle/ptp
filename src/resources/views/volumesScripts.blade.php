@if ($user->can('edit-in', $volume) && $volume->isImageVolume() && !$volume->hasTiledImages())
    {{vite_hot(base_path('vendor/biigle/ptp/hot'), ['src/resources/assets/js/main.js'], 'vendor/ptp')}}
    <script type="module">
        biigle.$declare('volumes.ptpIsRunning', {{ isset($volume->attrs['ptp_job_id']) ? 'true' : 'false' }} );
        biigle.$declare('volumes.ptpManualUrl', '{{route('manual-tutorials', ['ptp', 'ptp'])}}' );
    </script>
@endif


@extends('app')
@section('title', "{$volume->name} Point to Polygon Conversion")

@section('content')
    <div class="col-sm-8 col-sm-offset-2 col-lg-6 col-lg-offset-3">

    @include('ptp::show.ptp')
    </div>
@endsection

@section('navbar')
<div id="geo-navbar" class="navbar-text navbar-volumes-breadcrumbs">
    @include('volumes.partials.projectsBreadcrumb', ['projects' => $volume->projects]) / <a href="{{route('volume', $volume->id)}}">{{$volume->name}}</a> / <strong>Point to Polygon</strong>
</div>
@endsection

@push('scripts')
    <script src="{{ cachebust_asset('vendor/ptp/scripts/main.js') }}"></script>
    <script type="text/javascript">
        biigle.$declare('ptp.labels', {!! $labels !!});
        biigle.$declare('ptp.annotations', {!! $annotations !!});
        biigle.$declare('ptp.thumbnailWidth', {{ config('thumbnails.width') }});
        biigle.$declare('ptp.thumbnailHeight', {{ config('thumbnails.height') }});
        biigle.$declare('ptp.thumbnailEmptyUrl', '{{ asset(config('thumbnails.empty_url')) }}');
        biigle.$declare('ptp.showImageAnnotationRoute', '{{ route('show-image-annotation', '') }}/');
        biigle.$declare('ptp.showVideoAnnotationRoute', '{{ route('show-video-annotation', '') }}/');
        biigle.$declare('ptp.templateUrl', '{{ $largoPatchesUrl }}');
    </script>
@endpush

@push("styles")
<link href="{{ cachebust_asset('vendor/ptp/styles/main.css') }}" rel="stylesheet">
<link href="{{ cachebust_asset('vendor/largo/styles/main.css') }}" rel="stylesheet">

@endpush


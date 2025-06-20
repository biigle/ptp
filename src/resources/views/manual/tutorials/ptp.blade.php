
@extends('manual.base')

@section('manual-title', 'Point to Polygon conversion')

@section('manual-content')
    <div class="row">
        <p class="lead">
            The Point to Polygon conversion tool
        </p>
        <p>
            Many image collections are annotated using just point annotations, which in some cases are not really useful. With the Point to Polygon conversion, you can convert existing point annotations in a volume to polygons.
            You can access the tool through the tab with the <button class="btn btn-default btn-xs"><i class="fa fa-hat-wizard" aria-hidden="true"></i></button> icon in the volume overview.
        </p>
        <p>
            To use this feature, click on the <button class="btn btn-success btn-xs" aria-hidden="true">Submit</button> button in the tab and the conversion will start; depending on how many images and annotations are included in the volume, the conversion could take a long time.
        </p>
        <p class="text-center">
            <a href="{{asset('vendor/ptp/images/manual/points.png')}}"><img src="{{asset('vendor/ptp/images/manual/points.png')}}" width="50%"></a>
        </p>
        <p class="text-center">
            <i class="fa fa-arrow-down"></i>
        </p>
        <p class="text-center">
            <a href="{{asset('vendor/ptp/images/manual/polygons.png')}}"><img src="{{asset('vendor/ptp/images/manual/polygons.png')}}" width="50%"></a>
        </p>
        <p>
            In order for this conversion to work at its best, there need to be many point annotations for all the labels of interest. It might happen that not all of the points are converted; this depends on the algorithm being unable to detect a valid boundary.
        </p>
        <p>
            <b>Please note that this feature that it will <i>not</i> delete the existing point annotations and will attempt to convert all the point annotations in the volume.</b> To delete the point annotations fast, you can browse them in <a href="{{route('manual-tutorials', ['largo', 'largo'])}}">Largo using the Filter tab.</a>
        </p>
        <p>
             This feature is not available for volumes that include huge images or for video volumes.
        </p>
    </div>
@endsection

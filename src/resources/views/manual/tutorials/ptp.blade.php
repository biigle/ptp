
@extends('manual.base')

@section('manual-title', 'Point to Polygon conversion')

@section('manual-content')
    <div class="row">
        <p class="lead">
            The Point to Polygon conversion tool
        </p>
        <p>
            Many image collections are annotated using only point annotations, which are sometimes not very useful. The Point to Polygon conversion tool allows you to convert existing point annotations in a volume to polygons.
        </p>
        <p>
            Access the tool through the tab with the <button class="btn btn-default btn-xs"><i class="fa fa-hat-wizard" aria-hidden="true"></i></button> icon in the volume overview.
            To use this feature, click on the <button class="btn btn-success btn-xs" aria-hidden="true">Submit</button> button in the tab and the conversion will start; depending on how many images and annotations are included in the volume, it could take a long time.
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
             For this conversion to work optimally, there need to be numerous point annotations for each label of interest. Not all of the points may be converted, depending on the algorithm's ability to detect a valid boundary.
        </p>
        <p>
            <b>Please note that this feature that it will <i>not</i> delete the existing point annotations.</b> To delete the point annotations fast, you can browse them in <a href="{{route('manual-tutorials', ['largo', 'largo'])}}">Largo using the Filter tab.</a>
        </p>
        <p>
             This feature is not available for volumes that include huge images or for video volumes.
        </p>
    </div>
@endsection

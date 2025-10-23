
@extends('manual.base')

@section('manual-title', 'Magic SAM Point Conversion')

@section('manual-content')
    <div class="row">
        <p class="lead">
            Convert all point annotations of an image volume to polygon annotations using Magic SAM.
        </p>
        <p>
            Many image collections are annotated using only point annotations, which are sometimes not very useful. The Magic SAM point conversion tool allows you to convert existing point annotations of a whole image volume to polygons.
        </p>
        <div class="col-xs-6">
            <p class="text-center text-muted">
                <a href="{{asset('vendor/ptp/images/manual/points.png')}}"><img src="{{asset('vendor/ptp/images/manual/points.png')}}" width="100%"></a>
                before
            </p>
        </div>
        <div class="col-xs-6">
            <p class="text-center text-muted">
                <a href="{{asset('vendor/ptp/images/manual/polygons.png')}}"><img src="{{asset('vendor/ptp/images/manual/polygons.png')}}" width="100%"></a>
                after
            </p>
        </div>
        <p>
            Access the tool through the tab with the <button class="btn btn-default btn-xs"><i class="fa fa-hat-wizard" aria-hidden="true"></i></button> icon in the volume overview. Click on the <button class="btn btn-success btn-xs" aria-hidden="true">Submit</button> button in the tab and the conversion job will start. Depending on how many images and annotations are included in the volume, the job could take a long time. You will receive a notification once the job is finished.
        </p>
        <p>
             For this conversion to work optimally, there need to be numerous point annotations for each label of interest. Not all of the points may be converted, depending on the algorithm's ability to detect a valid boundary.
        </p>
        <p>
            <b>Existing point annotations will not be deleted as part of a conversion job.</b> To delete the point annotations you can browse them in <a href="{{route('manual-tutorials', ['largo', 'largo'])}}">Largo</a> and filter the annotations by shape.
        </p>
    </div>
@endsection

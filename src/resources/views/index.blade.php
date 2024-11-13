@extends('app')
@section('title', "{$volume->name} Point to Polygon Conversion")

@section('content')
<div class="container">
    <div class="col-sm-8 col-sm-offset-2 col-lg-6 col-lg-offset-3">
    Heyyy
    </div>
</div>
@endsection

@section('navbar')
<div id="geo-navbar" class="navbar-text navbar-volumes-breadcrumbs">
    @include('volumes.partials.projectsBreadcrumb', ['projects' => $volume->projects]) / <a href="{{route('volume', $volume->id)}}">{{$volume->name}}</a> / <strong>Point to Polygon</strong>
</div>
@endsection



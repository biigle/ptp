@if (($user->can('edit-in', $volume) || $user->can('sudo')) && $volume->isImageVolume() && !$volume->hasTiledImages())
    <sidebar-tab name="ptp" icon="circle" title="Perform Point to Polygon conversion" href="{{route('volumes-ptp-conversion', $volume->id)}}"></sidebar-tab>
@endif

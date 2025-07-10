@if ($user->can('edit-in', $volume) && $volume->isImageVolume())
    @if($volume->hasTiledImages())
        <sidebar-tab name="ptp" icon="hat-wizard" title="Magic SAM point conversion is not available for volumes with very large images" :disabled="true"></sidebar-tab>
    @else
        <sidebar-tab name="ptp" icon="hat-wizard" title="Convert Points to Polygons">
            <component :is="plugins.ptpForm"></component>
        </sidebar-tab>
    @endif
@endif

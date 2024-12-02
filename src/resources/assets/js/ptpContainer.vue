<template> <div class="ptp-container" >
            <div class="row">
        <div class="col-xs-8">
        <h3>Create a new Point to Polygon job (experimental)</h3>
        <span> Run the Point to polygon transformation using SAM</span><br>
            <span>Select for which label should we run the Point to Point annotation:</span>
                <select class='form-control' v-model=selectedLabel >
                    <option v-for="label in labels" :value="label.id">{{ label.name }}</option>
                </select>
            <!--<ptp-annotation-grid class="col-xs-6 ptp-cols":images="groupedAnnotations" ref="dismissGrid" empty-url="emptyUrl" :width="thumbnailWidth" :height="thumbnailHeight">
            </ptp-annotation-grid>-->

<div class=""><span>Here will be the graph</span></div>
<div class=""><a class="" target="_blank" title="Run Point to Polygon Conversion"><i class="fa fa-draw-polygon big-button" aria-hidden="true" @click="sendPtpRequest()"></i></a></div>

            </div>
    </div>
    </div>
</template>
<script>
import {AnnotationPatch, Messages} from './import';
import PtpAnnotationGrid from './components/ptpAnnotationGrid'
import PtpJobApi from './api/ptpJob'


export default {
    mixins: [AnnotationPatch],
    components: {
      ptpAnnotationGrid: PtpAnnotationGrid,
    },
    data(){
        let annotationsPerLabel = {};
        //TODO: Change to a more sensible name
        let thumbnailUrl = biigle.$require('ptp.imageUrls');
        let imageIndexes = {};
        biigle.$require('ptp.annotations').forEach(
            function (ann) {
                if (!annotationsPerLabel[ann['label_id']]){
                    annotationsPerLabel[ann['label_id']]= [];
                }
                annotationsPerLabel[ann['label_id']].push(ann);
            })
        return {
            imageIndexes: imageIndexes,
            annotations: annotationsPerLabel,
            showAnnotationOutlines: true,
            labels: biigle.$require('ptp.labels'),
            emptyUrl: biigle.$require('ptp.thumbnailEmptyUrl'),
            thumbnailWidth: biigle.$require('ptp.thumbnailWidth'),
            thumbnailHeight: biigle.$require('ptp.thumbnailHeight'),
            volumeId: biigle.$require('ptp.volumeId'),
            selectedLabel: null,
        }
    },
   methods: {

        sendPtpRequest(){
            if (!this.selectedLabel){
                //TODO: raise error if not selected
                Messages.danger("No label selected!");
                return
            }
            PtpJobApi.sendPtpJob({label_id: this.selectedLabel, volume_id: this.volumeId});
        }
    }
}
</script>

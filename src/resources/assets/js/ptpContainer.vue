<template> <div class="ptp-container" > <span><strong> Experimental </strong> Run the Point to polygon transformation using SAM</span>
        <div class="image-label-container" v-for="groupedAnnotations in annotations">
            <strong>{{ groupedAnnotations[0].label_name }} </strong>
            <div class="row">
            <ptp-annotation-grid class="col-xs-6 ptp-cols":images="groupedAnnotations" ref="dismissGrid" empty-url="emptyUrl" :width="thumbnailWidth" :height="thumbnailHeight">
</ptp-annotation-grid>
<div class="col-xs-6 ptp-cols"><span>Here will be the graph</span></div>
<div class="col-xs-3 ptp-button-cols"><a class="big-button" target="_blank" title="Compute Expected Polygon Area using the SAM Model" @click="sendComputeAreaRequest(groupedAnnotations)"><i class="fa fa-chart-area"></i></a></div>
<div class="col-xs-3 ptp-button-cols"><a class="big-button" target="_blank" title="Run Point to Polygon Conversion"><i class="fa fa-draw-polygon " aria-hidden="true" @click="sendPtpRequest(groupedAnnotations)"></i></a></div>


            </div>
    </div>
 </div>
</template>
<script>
import {AnnotationPatch, Events} from './import';
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
        }
    },
    provide() {
        const appData = {}

        // Need defineProperty to maintain reactivity.
        // See https://stackoverflow.com/questions/65718651/how-do-i-make-vue-2-provide-inject-api-reactive
        Object.defineProperty(appData, "showAnnotationOutlines", {
            get: () => this.showAnnotationOutlines,
        })

        return { 'outlines': appData };
    },
    methods: {
        sendComputeAreaRequest(annotations){
            console.log("Sending compute area request!");
            console.log("Label: " + annotations[0].label_id);
            PtpJobApi.sendPtpJob({job_type: 'compute-area', label_id: annotations[0].label_id, volume_id: this.volumeId});

        },
        sendPtpRequest(label){
            console.log("Sending PTP request");
            console.log("Label: " + annotations[0].label_id);
            PtpJobApi.sendPtpJob({job_type: 'ptp', label_id: annotations[0].label_id, volume_id: this.volumeId});
        }
    }
}
</script>

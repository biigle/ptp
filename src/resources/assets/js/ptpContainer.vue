<template>
    <div class="ptp-container" >
      <div class="row">
        <div class="col-xs-12">
            <h3>Create a new Point to Polygon job (experimental)</h3>
            <span> Run the Point to polygon transformation using SAM</span><br>
            <span>Select for which label should we run the Point to Point annotation:</span>
        </div>
      </div>
      <div class="row container-button-ptp">
            <div class="col-xs-6">
                <select class='form-control' v-model=selectedLabel >
                    <option v-for="label in labels" :value="label.id">{{ label.name }}</option>
                </select>
            </div>
            <div class="col-xs-6">
                <a class="" target="_blank" title="Run Point to Polygon Conversion">
                    <i class="fa fa-draw-polygon big-button" aria-hidden="true" @click="sendPtpRequest()"></i>
                </a>
            </div>
        </div>
    </div>
</template>
<script>
import {Messages} from './import';
import PtpJobApi from './api/ptpJob'


export default {
    data(){
        let annotationsPerLabel = {};
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
            volumeId: biigle.$require('ptp.volumeId'),
            selectedLabel: null,
        }
    },
   methods: {

        sendPtpRequest(){
            if (!this.selectedLabel){
                Messages.danger("No label selected!");
                return
            }
            PtpJobApi.sendPtpJob({label_id: this.selectedLabel, volume_id: this.volumeId});
        }
    }
}
</script>

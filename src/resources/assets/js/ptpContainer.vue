<template>
    <div class="ptp-container" >
      <div class="row">
        <div class="col-xs-12">
            <h4>Create a new Point to Polygon job (experimental)</h4>
            <span> Run the Point to polygon transformation using SAM</span><br>
        </div>
      </div>
      <div class="row container-button-ptp">
           <div class="col-xs-6">
                <a class="" target="_blank" title="Run Point to Polygon Conversion">
                    <i class="fa fa-draw-polygon big-button" aria-hidden="true" @click="sendPtpRequest()"></i>
                </a>
            </div>
        </div>
    </div>
</template>
<script>
import PtpJobApi from './api/ptpJob'
import {Messages} from './import'


export default {
    data(){
        let imageIndexes = {};
        return {
            imageIndexes: imageIndexes,
            volumeId: biigle.$require('volumes.volumeId'),
            selectedLabel: null,
        }
    },
  props: {
      volume: {
          type: Number,
          required: true,
        }
  },
   methods: {
        sendPtpRequest(){
            PtpJobApi.sendPtpJob({volume_id: this.volumeId}).catch(
                (error) => {
                    if (error.status == 400){
                        Messages.danger('The selected volume cannot be processed; it contains either videos or tiled images.')
                    }
                }
            );
        }
    }
}
</script>

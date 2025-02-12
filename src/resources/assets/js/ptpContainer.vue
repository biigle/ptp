<template>
    <div class="ptp-container">
        <form class="form-stacked">
            <div class="form-group">
                <h4>Create a new Point to Polygon job (experimental)</h4>
                <span> Run the Point to polygon transformation using SAM</span><br>
            </div>
            <div class="form-group">
              <button class="btn btn-success btn-block" type="button" title="Run Point to Polygon conversion on this volume" @click="sendPtpRequest">Submit</button>
            </div>
        </form>
    </div>
</template>
<script>
import PtpJobApi from './api/ptpJob'
import {Messages} from './import'


export default {
  data() {
        return {
            volumeId: biigle.$require('volumes.volumeId'),
            selectedLabel: null,
        }
  },
   methods: {
        sendPtpRequest(){
            PtpJobApi.sendPtpJob({volume_id: this.volumeId}).catch(
                (error) => {
                    if (error.status == 400){
                        Messages.danger(error.body['message'])
                    }
                }
            );
        }
    }
}
</script>

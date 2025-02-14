<template>
    <div class="ptp-container">
        <form class="form-stacked">
            <div class="form-group">
                <h4>Create a new Point to Polygon job <small><span class="label label-warning">experimental</span></small></h4>
                <span>Run the point to polygon transformation using Magic SAM</span><br>
            </div>
            <div class="form-group">
              <button
                  class="btn btn-success btn-block"
                  type="button"
                  title="Run Point to Polygon conversion on this volume"
                  @click="sendPtpRequest"
                  disabled="{{ this.isRunning }}">
                  Submit
              </button>
            </div>
        </form>
    </div>
</template>
<script>
import PtpJobApi from './api/ptpJob'
import {handleErrorResponse, Messages} from './import'


export default {
  data() {
        return {
            volumeId: biigle.$require('volumes.volumeId'),
            selectedLabel: null,
        }
  },
  created(){
    this.isRunning = biigle.$require('volumes.ptpJobId') !== null;
  },
  methods: {
        makeButtonDisabled(){
            this.isRunning = true;
        },
        sendPtpRequest() {
            PtpJobApi.save({id: this.volumeId}, {})
                .then(makeButtonDisabled, handleErrorResponse)
                .catch(
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

<template>
    <div class="ptp-container">
        <h4>
            <a :href="manualUrl" target="_blank" class="btn btn-default btn-xs pull-right" title="What is this?"><span class="fa fa-info-circle" aria-hidden="true"></span></a>
            Magic SAM<br>Point Conversion<sup>beta</sup>
        </h4>
        <p>
            This tool converts all point annotations in this volume to polygon annotations using Magic SAM. The original point annotations will not be deleted.
        </p>
        <p>
            <button
                class="btn btn-success btn-block"
                type="button"
                :title="ptpButtonTitle"
                :disabled="loading || null"
                @click="sendPtpRequest"
                >
                <loader :active="loading"></loader> Submit
            </button>
        </p>
        <p v-if="loading" class="text-info">
            The job was submitted. You will be notified when it is finished.
        </p>
    </div>
</template>
<script>
import PtpJobApi from "./api/ptpJob.js";
import { handleErrorResponse } from "./import.js";
import { LoaderMixin } from "./import.js";

export default {
    mixins: [LoaderMixin],
    data() {
        return {
            volumeId: biigle.$require("volumes.volumeId"),
            manualUrl: biigle.$require("volumes.ptpManualUrl"),
        };
    },

    computed: {
        ptpButtonTitle() {
            if (this.loading) {
                return "Another Point to Polygon conversion Job is already running on this volume!";
            }

            return "Run Point to Polygon conversion on this volume";
        }
    },

    methods: {
        sendPtpRequest() {
            PtpJobApi.save({ id: this.volumeId }, {})
                .then(this.startLoading, (e) => {
                    this.finishLoading();
                    handleErrorResponse(e);
                });
        }
    },

    created() {
        if (biigle.$require("volumes.ptpIsRunning") === true) {
            this.startLoading();
        }
    }
};
</script>

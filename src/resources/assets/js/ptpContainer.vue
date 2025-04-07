<template>
    <div class="ptp-container">
        <form class="form-stacked">
            <div class="form-group">
                <h4>
                    Create a new Point to Polygon job
                    <small>
                        <span class="label label-warning">experimental</span>
                    </small>
                </h4>
                <span
                    >Run the point to polygon transformation using Magic
                    SAM</span
                ><br />
            </div>
            <div class="form-group">
                <button
                    class="btn btn-success btn-block"
                    type="button"
                    :title="ptpButtonTitle"
                    @click="sendPtpRequest"
                    :disabled="isRunning"
                >
                    Submit
                </button>
            </div>
        </form>
    </div>
</template>
<script>
import PtpJobApi from "./api/ptpJob";
import { handleErrorResponse } from "./import";

export default {
    data() {
        return {
            volumeId: biigle.$require("volumes.volumeId"),
            selectedLabel: null,
            isRunning: false
        };
    },

    created() {
        this.isRunning = biigle.$require("volumes.isRunning");
    },

    computed: {
        ptpButtonTitle() {
            if (this.isRunning) {
                return "Another Point to Polygon conversion Job is already running on this volume!";
            }
            return "Run Point to Polygon conversion on this volume";
        }
    },

    methods: {
        makeButtonDisabled() {
            this.isRunning = true;
        },

        sendPtpRequest() {
            PtpJobApi.save({ id: this.volumeId }, {}).then(
                this.makeButtonDisabled,
                handleErrorResponse
            );
        }
    }
};
</script>

<template> <div class="ptp-container" > <span>This is the global container</span>
        <div v-for="groupedAnnotations in annotations">
            <strong>{{ groupedAnnotations[0].label_name }} </strong>
            <ptp-annotation-grid :images="groupedAnnotations" ref="dismissGrid" empty-url="emptyUrl" :width="thumbnailWidth" :height="thumbnailHeight">
</ptp-annotation-grid>
        </div>
    </div>
</template>
<script>
import AnnotationPtpTab from './components/annotationPtpTab';
import {AnnotationPatch} from './import';
import PtpAnnotationGrid from './components/ptpAnnotationGrid'


export default {
    mixins: [AnnotationPatch],
    components: {
      annotationPtpTab: AnnotationPtpTab,
      ptpAnnotationGrid: PtpAnnotationGrid,
    },
    props: {
    },
    data(){
        let annotationsPerLabel = {};
        let thumbnailUrl = biigle.$require('ptp.thumbnailUrl');
        biigle.$require('ptp.annotations').forEach(
            function (ann) {
                if (!annotationsPerLabel[ann['label_id']]){
                    annotationsPerLabel[ann['label_id']] = [];
                }
                ann['thumbnailUrl'] = thumbnailUrl;
                annotationsPerLabel[ann['label_id']].push(ann);
            })
        return {
            annotations: annotationsPerLabel,
            labels: biigle.$require('ptp.labels'),
            emptyUrl: biigle.$require('ptp.thumbnailEmptyUrl'),
            thumbnailWidth: biigle.$require('ptp.thumbnailWidth'),
            thumbnailHeight: biigle.$require('ptp.thumbnailHeight'),
        }
    },
    created() {
    }
}
</script>

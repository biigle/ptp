<template>
    <div class="ptp-container" >
        <span>This is the global container</span>
        <div           v-for="(labelAnnotation, key, index) in annotations">
            {{ key}}, {{index}}
            <span v-for="item in labelAnnotation"><br>LABEL: {{ item.label_id }} - {{ item.uuid }} - {{ item.id }}</span> <br>
        </div>

        <annotation-ptp-tab
            v-for="labelAnnotation in annotations">
            <span v-for="item in labelAnnotation">{{ item.label_id }} - {{ item.uuid }} - {{ item.id }} </span>
        </annotation-ptp-tab>
    </div>
</template>
<script>
import AnnotationPtpTab from './components/annotationPtpTab'


export default {
    components: {
      annotationPtpTab: AnnotationPtpTab
    },
    data(){
        let annotationsPerLabel = {};
        let annotations = biigle.$require('ptp.annotations').forEach(
            function (ann) {
                if (!annotationsPerLabel[ann['label_id']]){
                    annotationsPerLabel[ann['label_id']] = [];
                }
                annotationsPerLabel[ann['label_id']].push(ann);
            })
        console.log(annotationsPerLabel)
        return {
            annotations: annotationsPerLabel,
            labels: biigle.$require('ptp.labels'),

        }
    },
    created() {
    }
}
</script>

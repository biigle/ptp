<template>
    <figure class="image-grid__image image-grid__image--catalog" :class="classObject">
        <a v-if="showAnnotationLink" :href="showAnnotationLink" target="_blank" title="Show the annotation in the annotation tool">
            <img :src="srcUrl" @error="showEmptyImage">
            <img  v-show="overlayIsLoaded" :src="svgSrcUrl" @error="handleOverlayError" @load="handleOverlayLoad" class="outlines">
        </a>
        <img v-else :src="srcUrl" @error="showEmptyImage">
    </figure>
</template>

<script>
import {IMAGE_ANNOTATION} from '../constants';
import {AnnotationPatch, ImageGridImage} from '../import';

/**
 * A variant of the image grid image used for the annotation catalog
 *
 * @type {Object}
 */
export default {
    mixins: [
        ImageGridImage,
        AnnotationPatch,
    ],
    data() {
        return {
            showAnnotationRoute: null,
            overlayIsLoaded: false,
            overlayHasError: false,
        };
    },
    computed: {
        showAnnotationLink() {
            return this.showAnnotationRoute ? (this.showAnnotationRoute + this.image.id) : '';
        },
        svgSrcUrl() {
            // Replace file extension by svg file format
            return this.srcUrl.replace(/.[A-Za-z]*$/, '.svg');
        },
    },
    methods: {
        handleOverlayLoad() {
            this.overlayIsLoaded = true;
        },
        handleOverlayError() {
            this.overlayHasError = true;
        },
    },
    created() {
       this.showAnnotationRoute = biigle.$require('ptp.showImageAnnotationRoute');

    },
};
</script>

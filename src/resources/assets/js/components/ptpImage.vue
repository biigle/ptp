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
//TODO: find a good way to integrate a drawing of the expected area circle. Idea: use the point position of the annotation and draw the resulting circle  on the image?
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
        id() {
            return this.image.id;
        },
        uuid() {
            return this.image.uuid;
        },
        type() {
            return this.image.type;
        },
        patchPrefix() {
            return this.uuid[0] + this.uuid[1] + '/' + this.uuid[2] + this.uuid[3] + '/' + this.uuid;
        },
        showAnnotationLink() {
            return this.showAnnotationRoute ? (this.showAnnotationRoute + this.image.id) : '';
        },
        urlTemplate() {
            return biigle.$require('ptp.templateUrl');
        },
        svgSrcUrl() {
            // Replace file extension by svg file format
            return this.srcUrl.replace(/.[A-Za-z]*$/, '.svg');
        },
        showOutlines() {
            return !this.overlayHasError && this.outlines.showAnnotationOutlines
        }
    },
    methods: {

        handleOverlayLoad() {
            console.log("hey");
            this.overlayIsLoaded = true;
        },
        handleOverlayError() {
            this.overlayHasError = true;
        },

        getThumbnailUrl() {
            return this.urlTemplate
                .replace(':prefix', this.patchPrefix)
                .replace(':id', this.id);
        },

    },
    created() {
       this.showAnnotationRoute = biigle.$require('ptp.showImageAnnotationRoute');

    },
};
</script>

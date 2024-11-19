<script>
import {ImageGrid, Events} from '../import';
import Image from './ptpImage';

/**
 * A variant of the image grid used for the ptp catalog
 *
 * @type {Object}
 */
export default {
    mixins: [ImageGrid],
    components: {
        imageGridImage: Image,
    },
    computed: {
        rows() {
            //Force only one row to be displayed
            return 1
        },
        columns() {
            //Force only one column to be displayed
            return 1
        }

    },

    created() {
   },
};
</script>


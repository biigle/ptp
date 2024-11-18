<script>
import {ImageGrid} from '../import';
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
};
</script>


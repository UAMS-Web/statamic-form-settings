<!--
 * A heading on the form settings screen. The field wrapper renders the display
 * text and instructions, so the component itself renders nothing.
 -->
<script setup>
import { Fieldtype } from '@statamic/cms';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose } = Fieldtype.use(emit, props);
defineExpose(expose);
</script>

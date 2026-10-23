// Control Panel entry point. Components and fieldtypes register inside the booting callback so
// they run once Statamic has initialized.
import FormConditionalLogicFieldtype from './components/fieldtypes/FormConditionalLogicFieldtype.vue';
import FormEmailFieldSelectFieldtype from './components/fieldtypes/FormEmailFieldSelectFieldtype.vue';
import FormEmailRoutingFieldtype from './components/fieldtypes/FormEmailRoutingFieldtype.vue';
import FormSettingsSubsectionFieldtype from './components/fieldtypes/FormSettingsSubsectionFieldtype.vue';

Statamic.booting(() => {
    // The fieldtypes the form settings blueprint uses; each handle matches its PHP fieldtype
    Statamic.$components.register('form_conditional_logic-fieldtype', FormConditionalLogicFieldtype);
    Statamic.$components.register('form_email_field_select-fieldtype', FormEmailFieldSelectFieldtype);
    Statamic.$components.register('form_email_routing-fieldtype', FormEmailRoutingFieldtype);
    Statamic.$components.register('form_settings_subsection-fieldtype', FormSettingsSubsectionFieldtype);
});

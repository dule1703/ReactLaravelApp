import { tOr } from './i18n';

// Label of a changed field in the activity log. The entity is the prefix of the action
// ("transmission.updated" -> "transmission"), so the same column name can read differently per
// entity: activity.field.<entity>.<field> wins, then the generic activity.field.<field>,
// then the raw field name. This is the only place with that rule.
export function activityFieldLabel(action, field) {
    const entity = String(action ?? '').split('.')[0];

    return tOr(`activity.field.${entity}.${field}`, tOr(`activity.field.${field}`, field));
}

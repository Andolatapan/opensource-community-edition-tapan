import { ref } from "vue";

/**
 * Lets other toolbar controls open the Saved views "Save view" dialog.
 *
 * The dialog, its validation and the list it saves into all live in
 * SavedViewsMenu. The Filter popup's "Save filter" asks for it through this
 * module-level counter rather than rendering a second copy of the dialog, so
 * both entry points behave identically.
 */
const saveRequests = ref(0);

export function useSavedViewDialog() {
    return {
        saveRequests,
        requestSaveView: () => { saveRequests.value += 1; },
    };
}

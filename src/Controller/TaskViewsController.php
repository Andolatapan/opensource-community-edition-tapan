<?php

namespace App\Controller;

use App\Model\Table\SavedViewsTable;
use App\Model\Table\UserPreferencesTable;
use Cake\Log\Log;

/**
 * Hosts the Task Views single-page app.
 *
 * One Vue bundle serves every task tab (Views, Kanban, Calendar, Overview,
 * Subtasks, My Works); each action just tells the app which page to render via
 * the `page` view var. Kept out of EasycasesController so the SPA host does not
 * add surface to an already very large controller.
 */
class TaskViewsController extends AppController
{
    /**
     * Preferences handed to the bundle at render time, and the only keys
     * `savePreference()` will accept. Sending them with the page rather than
     * over a second request means the first paint already has the user's
     * layout — fetching it would show the default columns and then rearrange.
     */
    private const PREFERENCE_KEYS = ['taskViews.hiddenColumns'];

    public function index()
    {
        $this->renderApp('views');
    }

    public function kanban()
    {
        $this->renderApp('kanban');
    }

    public function calendar()
    {
        $this->renderApp('calendar');
    }

    public function overview()
    {
        $this->renderApp('overview');
    }

    public function subtasks()
    {
        $this->renderApp('subtasks');
    }

    public function myworks()
    {
        $this->renderApp('myworks');
    }

    /**
     * Store one preference for the logged-in user.
     *
     * The scope comes from the session, never from the request, so a caller
     * cannot write another user's row.
     */
    public function savePreference()
    {
        $this->request->allowMethod(['post']);
        $this->autoRender = false;

        $key = (string)$this->request->getData('key');
        if (!in_array($key, self::PREFERENCE_KEYS, true)) {
            return $this->response
                ->withStatus(400)
                ->withType('application/json')
                ->withStringBody((string)json_encode(['saved' => false]));
        }

        $raw = (string)$this->request->getData('value');
        $value = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || strlen($raw) > UserPreferencesTable::MAX_VALUE_BYTES) {
            return $this->response
                ->withStatus(400)
                ->withType('application/json')
                ->withStringBody((string)json_encode(['saved' => false]));
        }

        $saved = $this->fetchTable('UserPreferences')
            ->write((int)SES_COMP, (int)SES_ID, $key, $value);

        return $this->response
            ->withType('application/json')
            ->withStringBody((string)json_encode(['saved' => $saved]));
    }

    /**
     * Create a saved view, or overwrite an existing one's settings when `id` is
     * sent ("Save changes").
     *
     * Posts: page, name, state (JSON object), and optionally id.
     */
    public function saveView()
    {
        $this->request->allowMethod(['post']);
        $this->autoRender = false;

        $page = $this->viewPage();
        if ($page === null) {
            return $this->viewError(__('Saved views are not available on this page.'));
        }

        $raw = (string)$this->request->getData('state');
        $state = json_decode($raw, true);
        if (!is_array($state) || strlen($raw) > SavedViewsTable::MAX_STATE_BYTES) {
            return $this->viewError(__('That view could not be saved.'));
        }

        /** @var \App\Model\Table\SavedViewsTable $table */
        $table = $this->fetchTable('SavedViews');
        $company = (int)SES_COMP;
        $user = (int)SES_ID;
        $id = (int)$this->request->getData('id');

        try {
            if ($id) {
                // An update keeps the name unless a new one is sent with it.
                $entity = $table->findOwned($company, $user, $page, $id);
                if ($entity === null) {
                    return $this->viewError(__('That view no longer exists.'), 404);
                }
                $name = $this->request->getData('name') === null
                    ? $entity->name
                    : $this->cleanViewName();
            } else {
                if ($table->countFor($company, $user, $page) >= SavedViewsTable::MAX_PER_PAGE) {
                    return $this->viewError(__('You can save up to {0} views on a page. Delete one first.', SavedViewsTable::MAX_PER_PAGE));
                }

                $entity = $table->newEmptyEntity();
                $entity->set('company_id', $company);
                $entity->set('user_id', $user);
                $entity->set('page', $page);
                $entity->set('is_default', false);
                $name = $this->cleanViewName();
            }

            if ($name === null) {
                return $this->viewError(__('Give the view a name of up to {0} characters.', SavedViewsTable::MAX_NAME_LENGTH));
            }
            if ($table->nameTaken($company, $user, $page, $name, $id ?: null)) {
                return $this->viewError(__('You already have a view called "{0}".', $name), 409);
            }

            $entity->set('name', $name);
            $entity->set('state', (string)json_encode($state));
            if (!$table->save($entity)) {
                return $this->viewError(__('That view could not be saved.'), 500);
            }
        } catch (\Exception $e) {
            return $this->viewFailure($e);
        }

        return $this->viewResponse($page, (int)$entity->id);
    }

    /**
     * Rename a saved view. Posts: page, id, name.
     */
    public function renameView()
    {
        $this->request->allowMethod(['post']);
        $this->autoRender = false;

        $page = $this->viewPage();
        if ($page === null) {
            return $this->viewError(__('Saved views are not available on this page.'));
        }
        $name = $this->cleanViewName();
        if ($name === null) {
            return $this->viewError(__('Give the view a name of up to {0} characters.', SavedViewsTable::MAX_NAME_LENGTH));
        }

        /** @var \App\Model\Table\SavedViewsTable $table */
        $table = $this->fetchTable('SavedViews');
        $company = (int)SES_COMP;
        $user = (int)SES_ID;
        $id = (int)$this->request->getData('id');

        try {
            $entity = $table->findOwned($company, $user, $page, $id);
            if ($entity === null) {
                return $this->viewError(__('That view no longer exists.'), 404);
            }
            if ($table->nameTaken($company, $user, $page, $name, $id)) {
                return $this->viewError(__('You already have a view called "{0}".', $name), 409);
            }

            $entity->set('name', $name);
            if (!$table->save($entity)) {
                return $this->viewError(__('That view could not be renamed.'), 500);
            }
        } catch (\Exception $e) {
            return $this->viewFailure($e);
        }

        return $this->viewResponse($page, $id);
    }

    /**
     * Delete a saved view. Posts: page, id.
     */
    public function deleteView()
    {
        $this->request->allowMethod(['post']);
        $this->autoRender = false;

        $page = $this->viewPage();
        if ($page === null) {
            return $this->viewError(__('Saved views are not available on this page.'));
        }

        /** @var \App\Model\Table\SavedViewsTable $table */
        $table = $this->fetchTable('SavedViews');

        try {
            $entity = $table->findOwned((int)SES_COMP, (int)SES_ID, $page, (int)$this->request->getData('id'));
            // Already gone is what the caller wanted, so that is not an error.
            if ($entity !== null && !$table->delete($entity)) {
                return $this->viewError(__('That view could not be deleted.'), 500);
            }
        } catch (\Exception $e) {
            return $this->viewFailure($e);
        }

        return $this->viewResponse($page);
    }

    /**
     * Make a view the page's default, or clear the default with id 0.
     * Posts: page, id.
     */
    public function setDefaultView()
    {
        $this->request->allowMethod(['post']);
        $this->autoRender = false;

        $page = $this->viewPage();
        if ($page === null) {
            return $this->viewError(__('Saved views are not available on this page.'));
        }

        /** @var \App\Model\Table\SavedViewsTable $table */
        $table = $this->fetchTable('SavedViews');
        $company = (int)SES_COMP;
        $user = (int)SES_ID;
        $id = (int)$this->request->getData('id');

        try {
            if ($id && $table->findOwned($company, $user, $page, $id) === null) {
                return $this->viewError(__('That view no longer exists.'), 404);
            }
            $table->setDefault($company, $user, $page, $id ?: null);
        } catch (\Exception $e) {
            return $this->viewFailure($e);
        }

        return $this->viewResponse($page, $id ?: null);
    }

    /** The posted page, if it is one that can hold saved views. */
    private function viewPage(): ?string
    {
        $page = (string)$this->request->getData('page');

        return in_array($page, SavedViewsTable::PAGES, true) ? $page : null;
    }

    /** The posted name, trimmed, or null when it is empty or too long. */
    private function cleanViewName(): ?string
    {
        $name = trim((string)$this->request->getData('name'));
        if ($name === '' || mb_strlen($name) > SavedViewsTable::MAX_NAME_LENGTH) {
            return null;
        }

        return $name;
    }

    /**
     * Every successful write answers with the page's full list, so the client
     * replaces its copy rather than patching it and drifting from the server.
     */
    private function viewResponse(string $page, ?int $id = null)
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody((string)json_encode([
                'ok' => true,
                'id' => $id,
                'views' => $this->fetchTable('SavedViews')->listFor((int)SES_COMP, (int)SES_ID, $page),
            ]));
    }

    private function viewError(string $message, int $status = 400)
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode(['ok' => false, 'message' => $message]));
    }

    /** A database failure — most often the migration not having been run yet. */
    private function viewFailure(\Exception $e)
    {
        Log::warning('SavedViews write failed: ' . $e->getMessage());

        return $this->viewError(__('Saved views are unavailable right now. Please try again later.'), 500);
    }

    private function renderApp($page)
    {
        $this->viewBuilder()->setLayout('default_inner');
        $this->viewBuilder()->setTemplate('index');
        $this->set('page', $page);
        $this->set('pageTitle', __('Tasks'));
        $this->set('preferences', $this->fetchTable('UserPreferences')
            ->readMany((int)SES_COMP, (int)SES_ID, self::PREFERENCE_KEYS));
        // Shipped with the page, like the preferences above, so a default view
        // is applied before the first load rather than after it.
        $this->set('savedViews', in_array($page, SavedViewsTable::PAGES, true)
            ? $this->fetchTable('SavedViews')->listFor((int)SES_COMP, (int)SES_ID, $page)
            : []);
    }
}

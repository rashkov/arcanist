<?php

abstract class ArcanistRepositoryLocalState
  extends Phobject {

  private $repositoryAPI;
  private $shouldRestore;
  private $stashRef;
  private $workflow;

  final public function setWorkflow(ArcanistWorkflow $workflow) {
    $this->workflow = $workflow;
    return $this;
  }

  final public function getWorkflow() {
    return $this->workflow;
  }

  final public function setRepositoryAPI(ArcanistRepositoryAPI $api) {
    $this->repositoryAPI = $api;
    return $this;
  }

  final public function getRepositoryAPI() {
    return $this->repositoryAPI;
  }

  final public function saveLocalState() {
    $api = $this->getRepositoryAPI();

    $working_copy_display = tsprintf(
      "    %s: %s\n",
      pht('Working Copy'),
      $api->getPath());

    $conflicts = $api->getMergeConflicts();
    if ($conflicts) {
      echo tsprintf(
        "\n%!\n%W\n\n%s\n",
        pht('MERGE CONFLICTS'),
        pht('You have merge conflicts in this working copy.'),
        $working_copy_display);

      $lists = array();

      $lists[] = $this->newDisplayFileList(
        pht('Merge conflicts in working copy:'),
        $conflicts);

      $this->printFileLists($lists);

      throw new PhutilArgumentUsageException(
        pht(
          'Resolve merge conflicts before proceeding.'));
    }

    $externals = $api->getDirtyExternalChanges();
    if ($externals) {
      $message = pht(
        '%s submodule(s) have uncommitted or untracked changes:',
        new PhutilNumber(count($externals)));

      $prompt = pht(
        'Ignore the changes to these %s submodule(s) and continue?',
        new PhutilNumber(count($externals)));

      $list = id(new PhutilConsoleList())
        ->setWrap(false)
        ->addItems($externals);

      id(new PhutilConsoleBlock())
        ->addParagraph($message)
        ->addList($list)
        ->draw();

      $ok = phutil_console_confirm($prompt, $default_no = false);
      if (!$ok) {
        throw new ArcanistUserAbortException();
      }
    }

    $uncommitted = $api->getUncommittedChanges();
    $unstaged = $api->getUnstagedChanges();
    $untracked = $api->getUntrackedChanges();

    // We already dealt with externals.
    $unstaged = array_diff($unstaged, $externals);

    // We only want files which are purely uncommitted.
    $uncommitted = array_diff($uncommitted, $unstaged);
    $uncommitted = array_diff($uncommitted, $externals);

    if ($untracked || $unstaged || $uncommitted) {
      echo tsprintf(
        "\n%!\n%W\n\n%s\n",
        pht('UNCOMMITTED CHANGES'),
        pht('You have uncommitted changes in this working copy.'),
        $working_copy_display);

      $lists = array();

      $lists[] = $this->newDisplayFileList(
        pht('Untracked changes in working copy:'),
        $untracked);

      $lists[] = $this->newDisplayFileList(
        pht('Unstaged changes in working copy:'),
        $unstaged);

      $lists[] = $this->newDisplayFileList(
        pht('Uncommitted changes in working copy:'),
        $uncommitted);

      $this->printFileLists($lists);

      if ($untracked) {
        $hints = $this->getIgnoreHints();
        foreach ($hints as $hint) {
          echo tsprintf("%?\n", $hint);
        }
      }

      if ($this->canStashChanges()) {

        $query = pht('Stash these changes and continue?');

        $this->getWorkflow()
          ->getPrompt('arc.state.stash')
          ->setQuery($query)
          ->execute();

        $stash_ref = $this->saveStash();

        if ($stash_ref === null) {
          throw new Exception(
            pht(
              'Expected a non-null return from call to "%s->saveStash()".',
              get_class($this)));
        }

        $this->stashRef = $stash_ref;
      } else {
        throw new PhutilArgumentUsageException(
          pht(
            'You can not continue with uncommitted changes. Commit or '.
            'discard them before proceeding.'));
      }
    }

    $this->executeSaveLocalState();
    $this->shouldRestore = true;

    return $this;
  }

  final public function restoreLocalState() {
    $this->shouldRestore = false;

    $this->executeRestoreLocalState();
    if ($this->stashRef !== null) {
      $this->restoreStash($this->stashRef);
    }

    return $this;
  }

  final public function discardLocalState() {
    $this->shouldRestore = false;

    $this->executeDiscardLocalState();
    if ($this->stashRef !== null) {
      $this->restoreStash($this->stashRef);
      $this->discardStash($this->stashRef);
      $this->stashRef = null;
    }

    return $this;
  }

  final public function __destruct() {
    if ($this->shouldRestore) {
      $this->restoreLocalState();
    }

    $this->discardLocalState();
  }

  protected function canStashChanges() {
    return false;
  }

  protected function saveStash() {
    throw new PhutilMethodNotImplementedException();
  }

  protected function restoreStash($ref) {
    throw new PhutilMethodNotImplementedException();
  }

  protected function discardStash($ref) {
    throw new PhutilMethodNotImplementedException();
  }

  abstract protected function executeSaveLocalState();
  abstract protected function executeRestoreLocalState();
  abstract protected function executeDiscardLocalState();

  protected function getIgnoreHints() {
    return array();
  }

  final protected function newDisplayFileList($title, array $files) {
    if (!$files) {
      return null;
    }

    $items = array();
    $items[] = tsprintf("%s\n\n", $title);
    foreach ($files as $file) {
      $items[] = tsprintf(
        "    %s\n",
        $file);
    }

    return $items;
  }

  final protected function printFileLists(array $lists) {
    $lists = array_filter($lists);

    $last_key = last_key($lists);
    foreach ($lists as $key => $list) {
      foreach ($list as $item) {
        echo tsprintf('%B', $item);
      }
      if ($key !== $last_key) {
        echo tsprintf("\n\n");
      }
    }

    echo tsprintf("\n");
  }

}

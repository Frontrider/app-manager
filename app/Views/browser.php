<?= $this->include('partials/header') ?>

<main class="section app-catalog">
    <div class="container app-catalog-container">
        <?php if (session()->getFlashdata('success')): ?>
            <div class="notification is-success is-light" role="status">
                <?= esc(session()->getFlashdata('success')) ?>
            </div>
        <?php endif ?>
        <?php if (session()->getFlashdata('error')): ?>
            <div class="notification is-danger is-light" role="alert">
                <?= esc(session()->getFlashdata('error')) ?>
            </div>
        <?php endif ?>

        <?php $runningCount = count(array_filter($running)); ?>
        <div class="level mb-5">
            <div class="level-left">
                <div>
                    <h1 class="title is-4 mb-1">Available services</h1>
                </div>
            </div>
            <div class="level-right">
                <a class="tag is-info is-light is-medium" href="<?= esc(site_url('activity'), 'attr') ?>"
                   aria-label="Open application activity: <?= $runningCount ?> running">
                    <?= $runningCount ?> running
                </a>
            </div>
        </div>

        <form action="<?= esc(site_url('catalog/search'), 'attr') ?>" method="post" class="mb-5">
            <?= csrf_field() ?>
            <label class="label" for="catalog-search">Search applications</label>
            <div class="field has-addons">
                <div class="control is-expanded">
                    <input class="input" id="catalog-search" name="search" type="search"
                           value="<?= esc($searchQuery ?? '', 'attr') ?>" maxlength="200"
                           placeholder="Search all applications by name or category" autocomplete="off">
                </div>
                <div class="control"><button class="button is-link" type="submit">Search</button></div>
            </div>
        </form>
        <?php if ($apps === []): ?>
            <div class="notification is-light">No Compose files were found in the apps directory.</div>
        <?php else: ?>
            <div class="columns app-catalog-layout">
                <aside class="column is-2">
                    <nav class="menu" aria-label="App categories">
                        <p class="menu-label">Categories</p>
                        <ul class="menu-list">
                            <?php foreach ($categories as $category => $categoryApps): ?>
                                <li>
                                    <a href="<?= esc(site_url('category/' . implode('/', array_map('rawurlencode', explode(' / ', $category)))), 'attr') ?>"
                                       <?= $category === $selectedCategory ? 'class="is-active" aria-current="page"' : '' ?>>
                                        <?= esc(ucwords(str_replace(['-', '_'], ' ', $category))) ?>
                                    </a>
                                </li>
                            <?php endforeach ?>
                        </ul>
                        <?php if ($canManage ?? false): ?>
                            <form action="<?= esc(site_url('catalog/stop-all'), 'attr') ?>" method="post"
                                  class="mt-5">
                                <?= csrf_field() ?>
                                <button class="button is-danger is-light is-fullwidth" type="submit"
                                        aria-live="polite">Stop all services</button>
                            </form>
                            <a class="button is-link is-light is-fullwidth mt-3"
                               href="<?= esc(site_url('catalog/add'), 'attr') ?>">Add catalog item</a>
                        <?php endif ?>
                    </nav>
                </aside>
                <section class="column">
                <h2 class="title is-4"><?= $selectedCategory === null ? 'Search results' : esc(ucwords(str_replace(['-', '_'], ' ', $selectedCategory))) ?></h2>
                <div class="columns is-multiline">
                <?php foreach ($displayApps ?? $categories[$selectedCategory] ?? [] as $app): ?>
                    <?= view('partials/app_card', [
                        'app'       => $app,
                        'isRunning' => $running[$app['id']] ?? false,
                        'canManage' => $canManage ?? false,
                        'blockedBy' => $installationBlocks[$app['id']] ?? null,
                        'environmentFields' => $environments[$app['id']] ?? null,
                    ]) ?>
                <?php endforeach ?>
                </div>
                <?php if (($displayApps ?? $categories[$selectedCategory] ?? []) === []): ?>
                    <p class="notification is-light" role="status">No applications match your search.</p>
                <?php endif ?>
                </section>
            </div>
        <?php endif ?>
    </div>
</main>
<?= $this->include('partials/footer') ?>

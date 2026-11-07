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

        <div class="level mb-5">
            <div class="level-left">
                <div>
                    <h1 class="title is-4 mb-1">Available apps</h1>
                </div>
            </div>
            <div class="level-right">
                <span class="tag is-info is-light is-medium"><?= count(array_filter($running)) ?> running</span>
            </div>
        </div>

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
                    </nav>
                </aside>
                <section class="column">
                <h2 class="title is-4"><?= esc(ucwords(str_replace(['-', '_'], ' ', $selectedCategory))) ?></h2>
                <div class="columns is-multiline">
                <?php foreach ($categories[$selectedCategory] as $app): ?>
                    <?= view('partials/app_card', [
                        'app'       => $app,
                        'isRunning' => $running[$app['id']] ?? false,
                        'canManage' => $canManage ?? false,
                        'blockedBy' => $installationBlocks[$app['id']] ?? null,
                        'environmentFields' => $environments[$app['id']] ?? null,
                    ]) ?>
                <?php endforeach ?>
                </div>
                </section>
            </div>
        <?php endif ?>
    </div>
</main>
<?= $this->include('partials/footer') ?>

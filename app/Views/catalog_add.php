<?= $this->include('partials/header') ?>

<main class="section">
    <div class="container">
        <a class="button is-light mb-5" href="<?= esc(site_url('/'), 'attr') ?>">Back to applications</a>
        <h1 class="title is-4">Add catalog item</h1>

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

        <div class="columns">
            <section class="column">
                <h2 class="title is-5">Paste a Compose file</h2>
                <p class="help mb-4">
                    The service will be registered under the "other" category.
                </p>
                <form action="<?= esc(site_url('catalog/create'), 'attr') ?>" method="post">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="catalog-name">Application name</label>
                        <div class="control">
                            <input class="input" id="catalog-name" name="name" type="text"
                                   maxlength="120" required autocomplete="off">
                        </div>
                    </div>
                    <div class="field">
                        <label class="label" for="catalog-subpath">Subpath</label>
                        <div class="control">
                            <input class="input" id="catalog-subpath" name="subpath" type="text"
                                   placeholder="my-app" required autocomplete="off">
                        </div>
                        <p class="help">The url path for this service. Leading or trailing slashes are not required.</p>
                    </div>
                    <div class="field">
                        <label class="label" for="catalog-compose">Compose file</label>
                        <div class="control">
                            <textarea class="textarea is-family-monospace" id="catalog-compose" name="compose"
                                      rows="18" required spellcheck="false"
                                      placeholder="Paste the contents of a compose.yaml file here."></textarea>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <button class="button is-link" type="submit">Add application</button>
                        </div>
                    </div>
                </form>
            </section>

            <section class="column">
                <h2 class="title is-5">Upload a catalog ZIP</h2>
                <p class="help mb-4">
                    Maximum size: 50 MiB.
                </p>
                <form action="<?= esc(site_url('catalog/upload'), 'attr') ?>" method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="catalog-zip">Catalog ZIP</label>
                        <div class="control">
                            <div class="file">
                                <label class="file-label">
                                    <input class="file-input" id="catalog-zip" type="file" name="catalog"
                                           accept=".zip,application/zip" required>
                                    <span class="file-cta"><span class="file-label">Choose ZIP</span></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="field">
                        <div class="control">
                            <button class="button is-link" type="submit">Upload catalog</button>
                        </div>
                    </div>
                </form>
            </section>
        </div>
    </div>
</main>

<?= $this->include('partials/footer') ?>

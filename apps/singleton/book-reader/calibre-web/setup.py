import os
from pathlib import Path
import secrets
import subprocess
import sys

from ldap3 import Connection, Server, SUBTREE
from ldap3.utils.conv import escape_filter_chars


LIBRARY = "/Data/calibre-library"


def directory_users():
    base = os.environ.get("LDAP_USER_BASE_DN", "ou=people,dc=example,dc=com")
    groups = os.environ.get("LDAP_GROUP_BASE_DN", "ou=groups,dc=example,dc=com")
    password = Path("/config/ldap-password").read_text().strip()
    if not password:
        raise ValueError("Core's LDAP service password is empty.")
    server = Server(os.environ.get("LDAP_HOST", "core"),
                    port=int(os.environ.get("LDAP_PORT", "3890")), connect_timeout=10)
    with Connection(server, user="uid=service," + base, password=password,
                    auto_bind=True, raise_exceptions=True, receive_timeout=10) as connection:
        connection.search(base, "(objectClass=person)", SUBTREE, attributes=["uid", "mail"])
        users = {}
        for entry in connection.entries:
            name = str(entry.uid).strip()
            email = str(entry.mail).strip()
            if name == "service":
                continue
            if not name or not email:
                raise ValueError("Every Calibre-Web directory user must have a username and email.")
            if name in users or email.lower() in {user[0].lower() for user in users.values()}:
                raise ValueError("Duplicate LDAP usernames or emails cannot be synchronized.")
            users[name] = (email, False)
        connection.search(base, "(memberOf=" + escape_filter_chars("cn=lldap_admin," + groups) + ")",
                          SUBTREE, attributes=["uid"])
        for entry in connection.entries:
            name = str(entry.uid).strip()
            if name in users:
                users[name] = (users[name][0], True)
        if not any(admin for _, admin in users.values()):
            raise ValueError("An LLDAP administrator with an email is required.")
        return users


def application_config():
    # Initialize the pinned application's own schema, defaults, and encryption key.
    import calibreweb
    sys.path.insert(0, str(Path(calibreweb.__file__).parent))
    sys.argv = ["cps", "-p", "/config/app.db"]
    from cps import cli_param, config, config_sql, ub
    cli_param.init()
    ub.init_db(cli_param.settings_path)
    key, error = config_sql.get_encryption_key("/config")
    if error:
        raise RuntimeError(error)
    config_sql.load_configuration(ub.session, key)
    config.init_config(ub.session, key, cli_param)
    return config, ub


def synchronize_users(users, ub):
    from cps import constants
    from werkzeug.security import generate_password_hash
    ub.session.expire_all()
    existing = {user.name: user for user in ub.session.query(ub.User).all()}
    for name, (email, administrator) in users.items():
        user = existing.get(name)
        if user is None:
            user = ub.User(name=name, password="", sidebar_view=constants.ADMIN_USER_SIDEBAR)
            ub.session.add(user)
        user.email = email
        user.role = constants.ADMIN_USER_ROLES if administrator else (
            constants.ROLE_DOWNLOAD | constants.ROLE_VIEWER | constants.ROLE_EDIT_SHELFS
        )
        # No local fallback password: authentication must go through LLDAP.
        user.password = ""
    for name, user in existing.items():
        if name not in users and not user.role_anonymous():
            user.role = 0
            user.password = generate_password_hash(secrets.token_urlsafe(32))
    ub.session.commit()


def setup():
    subprocess.run(["calibredb", "list", "--with-library", LIBRARY], check=True)
    users = directory_users()
    config, ub = application_config()
    from cps import constants
    config.config_calibre_dir = LIBRARY
    config.config_login_type = constants.LOGIN_LDAP
    config.config_ldap_provider_url = os.environ.get("LDAP_HOST", "core")
    config.config_ldap_port = int(os.environ.get("LDAP_PORT", "3890"))
    config.config_ldap_authentication = 2
    config.config_ldap_serv_username = "uid=service," + os.environ.get(
        "LDAP_USER_BASE_DN", "ou=people,dc=example,dc=com"
    )
    config.config_ldap_serv_password_e = Path("/config/ldap-password").read_text().strip()
    config.config_ldap_dn = os.environ.get("LDAP_USER_BASE_DN", "ou=people,dc=example,dc=com")
    config.config_ldap_user_object = "(&(objectClass=person)(uid=%s))"
    config.config_ldap_openldap = True
    config.config_anonbrowse = 0
    config.config_public_reg = 0
    config.save()
    synchronize_users(users, ub)
    print("Calibre library and LLDAP authentication configured.", flush=True)


def main():
    serve = sys.argv[1:] == ["--serve"]
    if serve:
        password = Path("/global-env/lldap_service_password").read_text().strip()
        if not password:
            raise ValueError("Core's LDAP service password is empty.")
        password_file = Path("/config/ldap-password")
        password_file.touch(mode=0o600)
        password_file.chmod(0o600)
        password_file.write_text(password)
    setup()
    if serve:
        os.execvp("cps", ["cps", "-p", "/config/app.db", "-i", "0.0.0.0"])


if __name__ == "__main__":
    main()

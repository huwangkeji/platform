#!/usr/bin/env python3
"""MySQL DDL/seed -> SQLite 转换器（仅用于沙箱内运行验证，生产使用 schema.mysql.sql）"""
import re, sqlite3, sys, os

BASE = os.path.dirname(os.path.abspath(__file__))
PROJ = os.path.join(BASE, '..')
SCHEMA = os.path.join(PROJ, 'database', 'schema.mysql.sql')
SEED = os.path.join(PROJ, 'database', 'seed.sql')
OUT = os.path.join(PROJ, 'database', 'app_release.sqlite')

TYPE_MAP = [
    (r'BIGINT\s+UNSIGNED', 'INTEGER'),
    (r'BIGINT', 'INTEGER'),
    (r'TINYINT', 'INTEGER'),
    (r'MEDIUMINT', 'INTEGER'),
    (r'INT\b', 'INTEGER'),
    (r'SMALLINT', 'INTEGER'),
    (r'VARCHAR\(\d+\)', 'TEXT'),
    (r'CHAR\(\d+\)', 'TEXT'),
    (r'LONGTEXT', 'TEXT'),
    (r'TINYTEXT', 'TEXT'),
    (r'TEXT', 'TEXT'),
    (r'DATETIME', 'TEXT'),
    (r'TIMESTAMP', 'TEXT'),
    (r'DOUBLE', 'REAL'),
    (r'DECIMAL\([^)]*\)', 'REAL'),
    (r'\s+UNSIGNED', ''),
]

def conv_type(line):
    for pat, rep in TYPE_MAP:
        line = re.sub(pat, rep, line, flags=re.IGNORECASE)
    return line

def conv_create_table(stmt):
    m = re.search(r'CREATE TABLE\s+`?(\w+)`?\s*\((.*)\)\s*(?:ENGINE.*)?;?$', stmt, re.S | re.I)
    if not m:
        print('[skip] can not parse:', stmt[:60])
        return None
    tname, body = m.group(1), m.group(2)
    lines = [l.rstrip() for l in body.split('\n')]
    idxs = []
    out = []
    for raw in lines:
        line = raw.strip()
        if not line:
            continue
        # 注释
        line = re.sub(r"\s+COMMENT\s+'[^']*'", '', line)
        # 索引 / 唯一键
        mu = re.match(r'^UNIQUE\s+KEY\s+`?(\w+)`?\s*\((.+)\)\s*,?$', line)
        mi = re.match(r'^(?:INDEX|KEY)\s+`?(\w+)`?\s*\((.+)\)\s*,?$', line)
        if mu:
            out.append(f'UNIQUE ({mu.group(2).strip()})')
            continue
        if mi:
            idxs.append((tname, mi.group(1), mi.group(2).strip()))
            continue
        # 类型转换 / 自增
        line = conv_type(line)
        line = re.sub(r'AUTO_INCREMENT', '', line, flags=re.I)
        out.append(line.rstrip(','))
    # 清理: 每个字段行结尾逗号统一（最后保留)
    cleaned = []
    for i, l in enumerate(out):
        cleaned.append(l)
    # 重建为单语句, 处理逗号: 除最后一行外都以逗号结尾
    final = []
    for i, l in enumerate(cleaned):
        if i < len(cleaned) - 1:
            if not l.rstrip().endswith(','):
                l = l.rstrip() + ','
        else:
            l = l.rstrip().rstrip(',')
        final.append(l)
    create = f"CREATE TABLE {tname} (\n" + "\n".join(final) + "\n);"
    return create, idxs

def main():
    if os.path.exists(OUT):
        os.remove(OUT)
    conn = sqlite3.connect(OUT)
    cur = conn.cursor()

    # schema
    text = open(SCHEMA, encoding='utf-8').read()
    stmts = [s.strip() for s in re.split(r';\s*\n', text) if s.strip()]
    created = 0
    for stmt in stmts:
        if 'CREATE TABLE' not in stmt.upper():
            continue
        r = conv_create_table(stmt)
        if not r:
            sys.exit(1)
        create, idxs = r
        try:
            cur.execute(create)
        except Exception as e:
            print('FAIL CREATE:', create[:200])
            raise SystemExit(f'create error: {e}')
        for tname, iname, cols in idxs:
            cur.execute(f'CREATE INDEX {tname}_{iname} ON {tname} ({cols});')
        created += 1
    print('tables created:', created)

    # seed
    seed = open(SEED, encoding='utf-8').read()
    seed = re.sub(r'^USE\s+`?[\w.]+`?;', '', seed, flags=re.M | re.I)
    for stmt in [s.strip() for s in re.split(r';\s*\n', seed) if s.strip()]:
        cur.execute(stmt)
    conn.commit()

    # verify
    tables = [r[0] for r in cur.execute("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name").fetchall()]
    print('tables:', len(tables), tables)
    for t in ['admin_users', 'roles', 'permissions', 'admin_role', 'role_permission', 'channels']:
        n = cur.execute(f'SELECT COUNT(*) FROM {t}').fetchone()[0]
        print(f'  {t}: {n}')
    n = cur.execute('SELECT COUNT(*) FROM role_permission').fetchone()[0]
    print('  role_permission total:', n)
    conn.close()
    print('SQLite DB OK ->', OUT)

if __name__ == '__main__':
    main()
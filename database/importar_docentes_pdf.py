from __future__ import annotations

import argparse
import os
import re
from pathlib import Path

import pymupdf
import pymysql


def read_catalog(pdf_path: Path) -> list[tuple[str, list[str]]]:
    teachers: list[dict[str, object]] = []
    current: dict[str, object] | None = None

    for page_number, page in enumerate(pymupdf.open(pdf_path)):
        lines: dict[float, list[tuple]] = {}
        for word in page.get_text("words"):
            lines.setdefault(round(word[1], 1), []).append(word)

        for y_position, words in sorted(lines.items()):
            if y_position < (200 if page_number == 0 else 130):
                continue

            words.sort(key=lambda word: word[0])
            teacher_name = " ".join(word[4] for word in words if word[0] < 260).strip()
            unit_name = " ".join(word[4] for word in words if word[0] >= 260).strip()
            teacher_name = re.sub(r"\s+R$", "", teacher_name).strip()
            unit_name = re.sub(r"\s+R$", "", unit_name).strip()
            if unit_name == "R":
                unit_name = ""

            if teacher_name.upper().startswith(("NOMBRE PROFESOR", "UNIDADES DE APRENDIZAJE", "EN LOS SEMEST")):
                continue

            if teacher_name:
                is_wrapped_name = (
                    current is not None
                    and current["page"] == page_number + 1
                    and not unit_name
                    and len(teacher_name.split()) == 1
                )
                if current and (is_wrapped_name or not current["units"] and current["page"] == page_number + 1):
                    current["name"] = f"{current['name']} {teacher_name}".strip()
                else:
                    current = {"name": teacher_name, "units": [], "page": page_number + 1}
                    teachers.append(current)

            if (
                unit_name
                and current
                and unit_name.upper() not in {"NACIONAL", "DE CÓMPUTO", "ACADÉMICA"}
                and not unit_name.upper().startswith(("UNIDADES DE APRENDIZAJE", "EN LOS SEMEST"))
            ):
                current["units"].append(unit_name)

    catalog = [
        (str(teacher["name"]), list(dict.fromkeys(teacher["units"])))
        for teacher in teachers
    ]
    if not catalog or any(not units for _, units in catalog):
        raise ValueError("El PDF produjo docentes sin unidades; no se importó ningún dato.")
    if len({name for name, _ in catalog}) != len(catalog):
        raise ValueError("El PDF contiene nombres de profesor duplicados; revisa el catálogo antes de importarlo.")
    return catalog


def main() -> None:
    parser = argparse.ArgumentParser(description="Importa docentes y unidades desde DocentesEscom.pdf.")
    parser.add_argument("--dry-run", action="store_true", help="Solo cuenta los docentes y relaciones detectados.")
    parser.add_argument(
        "--replace",
        action="store_true",
        help="Reemplaza el catálogo normalizado actual, sin alterar cuentas ni calendarios.",
    )
    parser.add_argument(
        "--pdf",
        type=Path,
        default=Path(__file__).resolve().parents[1] / "DocentesEscom.pdf",
        help="Ruta al PDF fuente.",
    )
    arguments = parser.parse_args()
    catalog = read_catalog(arguments.pdf)
    assignment_count = sum(len(units) for _, units in catalog)
    if arguments.dry_run:
        print(f"PDF válido: {len(catalog)} docentes, {assignment_count} asignaciones.")
        return

    connection = pymysql.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", "3306")),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASS", ""),
        database=os.getenv("DB_NAME", "catt_prottocol"),
        charset="utf8mb4",
        autocommit=False,
    )
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT DATABASE()")
            schema_name = cursor.fetchone()[0]
            cursor.execute(
                "SELECT COUNT(*) FROM information_schema.tables "
                "WHERE table_schema = DATABASE() AND table_name = 'DocentesCatalogo'"
            )
            if cursor.fetchone()[0] != 1:
                raise RuntimeError(
                    "Faltan las tablas normalizadas. Ejecuta primero "
                    "20261002_normalizar_docentes_unidades.sql."
                )

            if arguments.replace:
                cursor.execute("DELETE FROM Docente_UnidadAprendizaje")
                cursor.execute("DELETE FROM DocentesCatalogo")
                cursor.execute("DELETE FROM UnidadAprendizaje")

            teacher_query = (
                "INSERT INTO DocentesCatalogo (NombreCompleto) VALUES (%s) "
                "ON DUPLICATE KEY UPDATE DocenteId = LAST_INSERT_ID(DocenteId)"
            )
            unit_query = (
                "INSERT INTO UnidadAprendizaje (Nombre) VALUES (%s) "
                "ON DUPLICATE KEY UPDATE UnidadId = LAST_INSERT_ID(UnidadId)"
            )
            link_query = (
                "INSERT IGNORE INTO Docente_UnidadAprendizaje (DocenteId, UnidadId) "
                "VALUES (%s, %s)"
            )
            for teacher_name, units in catalog:
                cursor.execute(teacher_query, (teacher_name,))
                teacher_id = cursor.lastrowid
                if not teacher_id:
                    cursor.execute(
                        "SELECT DocenteId FROM DocentesCatalogo WHERE NombreCompleto = %s",
                        (teacher_name,),
                    )
                    teacher_id = cursor.fetchone()[0]
                for unit_name in units:
                    cursor.execute(unit_query, (unit_name,))
                    unit_id = cursor.lastrowid
                    if not unit_id:
                        cursor.execute(
                            "SELECT UnidadId FROM UnidadAprendizaje WHERE Nombre = %s",
                            (unit_name,),
                        )
                        unit_id = cursor.fetchone()[0]
                    cursor.execute(link_query, (teacher_id, unit_id))
        connection.commit()
        print(
            f"Importación completada en {schema_name}: "
            f"{len(catalog)} docentes y {assignment_count} relaciones docente-unidad."
        )
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


if __name__ == "__main__":
    main()
#!/usr/bin/env python3
"""
Delete scraped content older than the retention window.
"""

import os
import sys

import mysql.connector
from mysql.connector import Error

import env_loader  # Auto-loads .env


DEFAULT_RETENTION_DAYS = 14


def get_retention_days():
    value = os.getenv("SCRAPER_RETENTION_DAYS", str(DEFAULT_RETENTION_DAYS))
    try:
        return int(value)
    except ValueError:
        raise ValueError("SCRAPER_RETENTION_DAYS must be an integer") from None


def connect_db():
    return mysql.connector.connect(
        host=os.getenv("DB_HOST"),
        database=os.getenv("DB_NAME"),
        user=os.getenv("DB_USER"),
        password=os.getenv("DB_PASS"),
    )


def delete_old_records(retention_days):
    conn = connect_db()
    cursor = conn.cursor()
    cursor.execute("SET time_zone = '-08:00'")

    counts = {}
    try:
        delete_statements = [
            (
                "article_categories",
                """
                DELETE ac
                FROM article_categories ac
                JOIN articles a ON ac.article_id = a.id
                WHERE a.scraped_at < DATE_SUB(NOW(), INTERVAL %s DAY)
                """,
            ),
            (
                "articles",
                """
                DELETE FROM articles
                WHERE scraped_at < DATE_SUB(NOW(), INTERVAL %s DAY)
                """,
            ),
            (
                "deal_categories",
                """
                DELETE dc
                FROM deal_categories dc
                JOIN deals d ON dc.deal_id = d.id
                WHERE d.scraped_at < DATE_SUB(NOW(), INTERVAL %s DAY)
                """,
            ),
            (
                "deals",
                """
                DELETE FROM deals
                WHERE scraped_at < DATE_SUB(NOW(), INTERVAL %s DAY)
                """,
            ),
        ]

        for label, sql in delete_statements:
            cursor.execute(sql, (retention_days,))
            counts[label] = cursor.rowcount

        conn.commit()
        return counts
    except Error:
        conn.rollback()
        raise
    finally:
        cursor.close()
        conn.close()


def main():
    try:
        retention_days = get_retention_days()
    except ValueError as exc:
        print(f"ERROR: {exc}")
        return 1

    if retention_days < 1:
        print("ERROR: SCRAPER_RETENTION_DAYS must be at least 1")
        return 1

    try:
        counts = delete_old_records(retention_days)
    except Error as exc:
        print(f"ERROR: Cleanup failed: {exc}")
        return 1

    print(
        "Cleanup complete "
        f"(retention: {retention_days} days; "
        f"articles: {counts['articles']}, "
        f"article_categories: {counts['article_categories']}, "
        f"deals: {counts['deals']}, "
        f"deal_categories: {counts['deal_categories']})"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())

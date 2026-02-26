#!/usr/bin/env python3
"""
Daily Reset Script for Failed Article Summary Retries
Resets summary_retry_count to 0 for all failed articles to allow fresh retry attempts
Runs daily at midnight via cron
"""

import os
import sys
import mysql.connector
from mysql.connector import Error
from dotenv import load_dotenv
from datetime import datetime

# Load environment variables
load_dotenv()

class RetryCountResetter:
    def __init__(self):
        # Database configuration from .env
        self.db_config = {
            'host': os.getenv('DB_HOST'),
            'database': os.getenv('DB_NAME'),
            'user': os.getenv('DB_USER'),
            'password': os.getenv('DB_PASS')
        }

    def log(self, message):
        """Print timestamped log message"""
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        print(f"[{timestamp}] {message}")

    def connect_db(self):
        """Establish database connection"""
        try:
            connection = mysql.connector.connect(**self.db_config)
            if connection.is_connected():
                self.log("Successfully connected to database")
                return connection
        except Error as e:
            self.log(f"Error connecting to database: {e}")
            return None

    def reset_retry_counts(self):
        """Reset retry counts for all failed articles"""
        connection = None

        try:
            connection = self.connect_db()
            if not connection:
                self.log("Failed to connect to database. Exiting.")
                return False

            cursor = connection.cursor()

            # Set timezone to PST
            cursor.execute("SET time_zone = '-08:00'")

            # First, count how many articles will be reset
            count_query = """
                SELECT COUNT(*) as count
                FROM articles
                WHERE isSummaryFailed = 'Y'
            """
            cursor.execute(count_query)
            result = cursor.fetchone()
            failed_count = result[0] if result else 0

            self.log(f"Found {failed_count} failed articles to reset")

            if failed_count == 0:
                self.log("No failed articles found. Nothing to reset.")
                return True

            # Reset retry counts and last attempt timestamp
            reset_query = """
                UPDATE articles
                SET summary_retry_count = 0,
                    summary_last_attempt = NULL
                WHERE isSummaryFailed = 'Y'
            """

            cursor.execute(reset_query)
            connection.commit()

            rows_affected = cursor.rowcount
            self.log(f"Successfully reset retry counts for {rows_affected} articles")

            # Verify the reset
            verify_query = """
                SELECT COUNT(*) as count
                FROM articles
                WHERE isSummaryFailed = 'Y'
                AND summary_retry_count = 0
            """
            cursor.execute(verify_query)
            result = cursor.fetchone()
            verified_count = result[0] if result else 0

            self.log(f"Verification: {verified_count} articles now have retry_count = 0")

            return True

        except Error as e:
            self.log(f"Database error occurred: {e}")
            if connection:
                connection.rollback()
            return False

        except Exception as e:
            self.log(f"Unexpected error occurred: {e}")
            if connection:
                connection.rollback()
            return False

        finally:
            if connection and connection.is_connected():
                cursor.close()
                connection.close()
                self.log("Database connection closed")

def main():
    """Main execution function"""
    resetter = RetryCountResetter()

    resetter.log("=" * 60)
    resetter.log("Starting daily retry count reset process")
    resetter.log("=" * 60)

    success = resetter.reset_retry_counts()

    if success:
        resetter.log("Retry count reset completed successfully")
        sys.exit(0)
    else:
        resetter.log("Retry count reset failed")
        sys.exit(1)

if __name__ == "__main__":
    main()

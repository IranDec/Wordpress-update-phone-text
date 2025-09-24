# Adschi Search and Replace

A simple and powerful WordPress plugin to search and replace text throughout your entire database. This plugin is designed to be a helpful tool for developers and site administrators who need to perform bulk updates, such as changing a phone number, updating a domain name, or correcting a recurring typo.

## Features

*   **Database-wide Search and Replace:**  Performs a search and replace operation across all tables in your WordPress database.
*   **Serialized Data Support:**  Correctly handles serialized data, making it compatible with page builders like Divi and Elementor, as well as many other plugins and themes that store data in this format.
*   **Case-Insensitive and Case-Sensitive Search:**  By default, the search is case-insensitive. A "Case-sensitive" option is available for more precise searches.
*   **Match Whole Value Only:**  Provides an option to replace the search term only when it matches the entire value in a database field.
*   **History Log:**  Keeps a detailed history of all search and replace operations, including the search and replace terms, the number of rows and tables affected, the user who performed the operation, and the date and time.
*   **Safety First:**  For safety, the `users` and `usermeta` tables are excluded from all search and replace operations.

## Installation

1.  Download the plugin from the [GitHub repository](https://github.com/your-repo/adschi-search-replace).
2.  In your WordPress admin dashboard, navigate to **Plugins > Add New**.
3.  Click the **Upload Plugin** button and select the downloaded ZIP file.
4.  Activate the plugin.

## How to Use

1.  **Navigate to the Plugin:**  Go to **Tools > Adschi Search & Replace** in your WordPress admin dashboard.
2.  **Enter Search and Replace Terms:**
    *   **Find:**  Enter the text you want to search for.
    *   **Replace with:**  Enter the text you want to replace it with.
3.  **Choose Your Options:**
    *   **Case-sensitive:**  Check this box if you want the search to be case-sensitive.
    *   **Match whole value only:**  Check this box if you want to replace the search term only when it is an exact match for the entire value in a database field.
4.  **Run the Search/Replace:**  Click the **Run Search/Replace** button.

**IMPORTANT:**  This plugin directly modifies your database. Always back up your database before running a search and replace operation.

## History

To view a log of all past search and replace operations, navigate to **Tools > Adschi Search & Replace > History**. This page will show you a detailed list of all the operations that have been performed.

## Disclaimer

This plugin is provided as-is, without any warranty. The author is not responsible for any data loss or damage that may occur from using this plugin. Always back up your database before using this tool.
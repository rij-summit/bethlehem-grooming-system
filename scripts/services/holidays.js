/**
 * Philippine Holiday Service
 *
 * Purpose:
 * Fetch Philippine public holidays from a public API.
 *
 * Note for backend developer:
 * This is only used by the frontend for user experience.
 * Holiday validation should still be checked again on the backend.
 */

const holidayCache = new Map();

export async function getPhilippineHolidays(year) {
  if (holidayCache.has(year)) {
    return holidayCache.get(year);
  }

  try {
    const response = await fetch(
      `https://date.nager.at/api/v3/PublicHolidays/${year}/PH`,
    );

    if (!response.ok) {
      throw new Error(`Holiday API request failed: ${response.status}`);
    }

    const holidays = await response.json();

    // Store as: "YYYY-MM-DD" -> holiday object
    const holidayMap = new Map();

    holidays.forEach((holiday) => {
      holidayMap.set(holiday.date, holiday);
    });

    holidayCache.set(year, holidayMap);
    return holidayMap;
  } catch (error) {
    console.error("Failed to fetch Philippine holidays:", error);

    // Fail safely so the UI still works even if API fails
    const emptyMap = new Map();
    holidayCache.set(year, emptyMap);
    return emptyMap;
  }
}

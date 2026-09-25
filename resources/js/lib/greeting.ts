/**
 * Time-of-day greeting for the given moment, read in the display timezone:
 * pagi 04–10, siang 11–14, sore 15–17, malam 18–03.
 */
export function greeting(at: Date, timeZone: string): string {
    const hour = Number(
        new Intl.DateTimeFormat('en-US', {
            hour: 'numeric',
            hourCycle: 'h23',
            timeZone,
        }).format(at),
    );

    if (hour >= 4 && hour < 11) {
        return 'Selamat pagi';
    }

    if (hour >= 11 && hour < 15) {
        return 'Selamat siang';
    }

    if (hour >= 15 && hour < 18) {
        return 'Selamat sore';
    }

    return 'Selamat malam';
}

namespace TaxiService.Domain.Enums;

public enum OrderStatus
{
    Created = 0,
    Searching = 1,
    DriverAssigned = 2,
    DriverEnRoute = 3,
    DriverArrived = 4,
    InProgress = 5,
    Completed = 6,
    Cancelled = 7,
    NoDriverFound = 8
}

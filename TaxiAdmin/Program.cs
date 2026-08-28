using Microsoft.EntityFrameworkCore;
using TaxiAdmin.Components;
using TaxiService.Core.Services;
using TaxiService.Infrastructure.Data;

var builder = WebApplication.CreateBuilder(args);

builder.Services.AddRazorComponents()
    .AddInteractiveServerComponents();

builder.Services.AddDbContext<TaxiDbContext>(options =>
    options.UseNpgsql(
        "Host=localhost;Port=5432;Database=taxi_tyumen_dev;Username=postgres;Password=postgres123"));

builder.Services.AddScoped<IBalanceService, BalanceService>();

var app = builder.Build();

if (!app.Environment.IsDevelopment())
{
    app.UseExceptionHandler("/Error", createScopeForErrors: true);
    app.UseHsts();
}

app.UseHttpsRedirection();
app.UseStaticFiles();
app.UseAntiforgery();

app.MapRazorComponents<App>()
    .AddInteractiveServerRenderMode();

app.Run();

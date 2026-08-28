using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace TaxiService.Infrastructure.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddPickupEntrance : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<string>(
                name: "PickupEntrance",
                table: "orders",
                type: "text",
                nullable: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(
                name: "PickupEntrance",
                table: "orders");
        }
    }
}

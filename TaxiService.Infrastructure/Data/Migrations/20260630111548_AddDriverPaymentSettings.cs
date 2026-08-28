using Microsoft.EntityFrameworkCore.Migrations;

#nullable disable

namespace TaxiService.Infrastructure.Data.Migrations
{
    /// <inheritdoc />
    public partial class AddDriverPaymentSettings : Migration
    {
        /// <inheritdoc />
        protected override void Up(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.AddColumn<bool>(
                name: "AcceptCardTransfer",
                table: "drivers",
                type: "boolean",
                nullable: false,
                defaultValue: false);

            migrationBuilder.AddColumn<bool>(
                name: "AcceptSbp",
                table: "drivers",
                type: "boolean",
                nullable: false,
                defaultValue: false);

            migrationBuilder.AddColumn<string>(
                name: "PaymentBankName",
                table: "drivers",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "PaymentCardHolder",
                table: "drivers",
                type: "text",
                nullable: true);

            migrationBuilder.AddColumn<string>(
                name: "PaymentPhone",
                table: "drivers",
                type: "text",
                nullable: true);
        }

        /// <inheritdoc />
        protected override void Down(MigrationBuilder migrationBuilder)
        {
            migrationBuilder.DropColumn(
                name: "AcceptCardTransfer",
                table: "drivers");

            migrationBuilder.DropColumn(
                name: "AcceptSbp",
                table: "drivers");

            migrationBuilder.DropColumn(
                name: "PaymentBankName",
                table: "drivers");

            migrationBuilder.DropColumn(
                name: "PaymentCardHolder",
                table: "drivers");

            migrationBuilder.DropColumn(
                name: "PaymentPhone",
                table: "drivers");
        }
    }
}
